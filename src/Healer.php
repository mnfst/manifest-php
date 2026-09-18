<?php declare(strict_types=1);

namespace Mnfst;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * One captured failure, start to finish: capture, heal, apply, retry once,
 * report, notify. Shared by every client hook so they cannot drift apart; a
 * hook only knows how to send a Retry with the client that made the call.
 *
 * Fails open at every step: any problem returns null and the caller serves
 * the original response (spec section 5, rule 5).
 */
final class Healer
{
    /** @var (callable(string): StreamInterface)|null */
    private $streamFor;

    /**
     * @param (callable(string): StreamInterface)|null $streamFor builds a body stream the way the client does,
     *   so a non-seekable response body can be handed back readable after the SDK read it
     */
    public function __construct(
        private readonly Config $config,
        private readonly HealApi $api,
        ?callable $streamFor = null,
    ) {
        $this->streamFor = $streamFor;
    }

    /**
     * @param float $started microtime(true) when the original request went out
     * @param callable(Retry): ResponseInterface $send replays with the original client
     * @return ResponseInterface the response the caller gets: the retry's, or the
     *   original one, rebuilt when the SDK had to consume its body to read it
     */
    public function attempt(RequestInterface $request, ResponseInterface $response, float $started, callable $send): ResponseInterface
    {
        if (!Gate::shouldCapture($response->getStatusCode())) {
            return $response;
        }

        $healStarted = microtime(true);
        $result = null;
        $replayStatus = null;
        $replayAttempted = false;
        try {
            $contentType = Bodies::contentTypeOf($request->getHeaders());
            [$requestBody, $oversized] = $this->read($request->getBody(), Gate::REQUEST_BODY_LIMIT);
            [$body, $replayable] = $oversized
                ? [null, false]   // past the limit it is a payload, not a form to repair: reported absent, not retried
                : Bodies::parseRequestBody($requestBody, $contentType);
            [$raw, $response] = $this->readResponse($response);
            [$responseBody, $truncated] = Wire::cappedResponseBody($raw);

            $result = $this->api->heal(Wire::healPayload(
                bin2hex(random_bytes(16)),
                $request->getMethod(),
                (string) $request->getUri(),
                $request->getHeaders(),
                $body,
                $response->getStatusCode(),
                $responseBody,
                $truncated,
                (int) round((microtime(true) - $started) * 1000),
            ));
            if (!is_array($result)) {
                return $response;
            }
            $attemptId = $result['healAttemptId'] ?? null;

            $retry = Retry::build(
                $request->getMethod(),
                (string) $request->getUri(),
                $request->getHeaders(),
                $body,
                $replayable,
                $contentType,
                $result,
            );
            if ($retry === null) {
                $this->abandon($attemptId);

                return $response;
            }

            $replayAttempted = true;
            try {
                $replayed = HealApi::withInternalCall(static fn (): ResponseInterface => $send($retry));
            } catch (\Throwable $e) {
                $this->report($attemptId, null, null, 'transport_error: ' . $e->getMessage());

                return $response;
            }

            $replayStatus = $replayed->getStatusCode();
            $failedBody = null;
            if ($replayStatus >= 400) {
                [$raw, $replayed] = $this->readResponse($replayed);
                $failedBody = Wire::cappedResponseBody($raw)[0];
            }
            $this->report($attemptId, $replayStatus, is_array($failedBody) ? $failedBody : null);

            return $replayed;
        } catch (\Throwable) {
            return $response;
        } finally {
            $this->notify(
                $request,
                $response,
                $result,
                $replayAttempted && $replayStatus === null ? 'replay_failed' : ($result['status'] ?? 'heal_unreachable'),
                $replayStatus,
                (int) round((microtime(true) - $healStarted) * 1000),
            );
        }
    }

    /**
     * Up to $limit bytes of a stream plus one to know it holds more, without
     * leaving a mark: a seekable stream is put back where it was, so the
     * caller's own getContents() still works. A 40 MB upload that failed
     * with a 413 is never copied into memory to learn it is over the limit.
     *
     * @return array{0: string, 1: bool} the bytes read and whether the stream holds more than $limit
     */
    private function read(StreamInterface $stream, int $limit): array
    {
        $size = $stream->getSize();
        if ($size !== null && $size > $limit) {
            return ['', true];
        }
        $seekable = $stream->isSeekable();
        $position = $seekable ? $stream->tell() : 0;
        if ($seekable) {
            $stream->rewind();
        }
        try {
            $raw = '';
            while (!$stream->eof() && strlen($raw) <= $limit) {
                $chunk = $stream->read(min(65536, $limit + 1 - strlen($raw)));
                if ($chunk === '') {
                    break;
                }
                $raw .= $chunk;
            }

            return [$raw, strlen($raw) > $limit];
        } finally {
            if ($seekable) {
                $stream->seek($position);
            }
        }
    }

    /**
     * The response body, capped, and a response the caller can still read it
     * from. A seekable body is read up to the cap plus one byte, enough to
     * know it was cut. A non-seekable one (Guzzle `stream => true`) is
     * consumed by reading, so it is read whole and replaced by an in-memory
     * copy when the client gave us a way to build one.
     *
     * @return array{0: string, 1: ResponseInterface}
     */
    private function readResponse(ResponseInterface $response): array
    {
        $stream = $response->getBody();
        if ($stream->isSeekable() || $this->streamFor === null) {
            return [$this->read($stream, Wire::RESPONSE_BODY_CAP)[0], $response];
        }
        $raw = $stream->getContents();

        return [$raw, $response->withBody(($this->streamFor)($raw))];
    }

    /** Close an attempt we opened but could not replay. */
    private function abandon(mixed $attemptId): void
    {
        $this->report($attemptId, null, null, HealApi::NOT_ATTEMPTED);
    }

    private function report(mixed $attemptId, ?int $status, ?array $failedBody, ?string $error = null): void
    {
        if (is_string($attemptId)) {
            $this->api->reportOutcome($attemptId, $status, $failedBody, $error);
        }
    }

    /** The onHeal callback, when configured. It must never break the app. */
    private function notify(
        RequestInterface $request,
        ResponseInterface $response,
        ?array $result,
        mixed $healStatus,
        ?int $replayStatus,
        int $healMs,
    ): void {
        $onHeal = $this->config->onHeal;
        if (!is_callable($onHeal)) {
            return;
        }
        try {
            $operations = $result['operations'] ?? null;
            $onHeal(new HealEvent(
                Wire::safeUrl((string) $request->getUri()),
                $response->getStatusCode(),
                is_string($healStatus) ? $healStatus : 'heal_unreachable',
                $replayStatus,
                $healMs,
                is_array($operations) ? $operations : null,
            ));
        } catch (\Throwable) {
            trigger_error('manifest: the onHeal callback threw', E_USER_WARNING);
        }
    }
}
