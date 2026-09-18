<?php declare(strict_types=1);

namespace Mnfst;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

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
    public function __construct(
        private readonly Config $config,
        private readonly HealApi $api,
    ) {
    }

    /**
     * @param float $started microtime(true) when the original request went out
     * @param callable(Retry): ResponseInterface $send replays with the original client
     * @return ResponseInterface|null the retry's response, or null to keep the original
     */
    public function attempt(RequestInterface $request, ResponseInterface $response, float $started, callable $send): ?ResponseInterface
    {
        if (!Gate::shouldCapture($response->getStatusCode())) {
            return null;
        }

        $healStarted = microtime(true);
        $result = null;
        $replayStatus = null;
        $replayAttempted = false;
        try {
            $contentType = Bodies::contentTypeOf($request->getHeaders());
            [$body, $replayable] = Bodies::parseRequestBody((string) $request->getBody(), $contentType);
            [$responseBody, $truncated] = Wire::cappedResponseBody((string) $response->getBody());

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
                return null;
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

                return null;
            }

            $replayAttempted = true;
            try {
                $replayed = HealApi::withInternalCall(static fn (): ResponseInterface => $send($retry));
            } catch (\Throwable $e) {
                $this->report($attemptId, null, null, 'transport_error: ' . $e->getMessage());

                return null;
            }

            $replayStatus = $replayed->getStatusCode();
            $failedBody = $replayStatus >= 400 ? Wire::cappedResponseBody((string) $replayed->getBody())[0] : null;
            $this->report($attemptId, $replayStatus, is_array($failedBody) ? $failedBody : null);

            return $replayed;
        } catch (\Throwable) {
            return null;
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
