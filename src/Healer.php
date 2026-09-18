<?php declare(strict_types=1);

namespace Mnfst;

/**
 * One captured failure, start to finish: heal, apply, retry once, report,
 * notify. Shared by every client hook so they cannot drift apart; a hook only
 * knows how to describe its call as a Capture and how to send a Retry with
 * the client that made the call.
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
     * @param callable(Retry): Replay $send replays with the original client
     * @return Replay|null the retry, or null to keep the original response
     */
    public function attempt(Capture $capture, callable $send): ?Replay
    {
        if (!Gate::shouldCapture($capture->status)) {
            return null;
        }

        $healStarted = microtime(true);
        $result = null;
        $replayStatus = null;
        $replayAttempted = false;
        try {
            $contentType = Bodies::contentTypeOf($capture->headers);
            [$body, $replayable] = $capture->oversized
                ? [null, false]   // past the limit it is a payload, not a form to repair: reported absent, not retried
                : Bodies::parseRequestBody($capture->body, $contentType);
            [$responseBody, $truncated] = Wire::cappedResponseBody($capture->responseBody);

            $result = $this->api->heal(Wire::healPayload(
                bin2hex(random_bytes(16)),
                $capture->method,
                $capture->url,
                $capture->headers,
                $body,
                $capture->status,
                $responseBody,
                $truncated,
                (int) round((microtime(true) - $capture->started) * 1000),
            ));
            if (!is_array($result)) {
                return null;
            }
            $attemptId = $result['healAttemptId'] ?? null;

            $retry = Retry::build(
                $capture->method,
                $capture->url,
                $capture->headers,
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
                $replay = HealApi::withInternalCall(static fn (): Replay => $send($retry));
            } catch (\Throwable $e) {
                $this->report($attemptId, null, null, 'transport_error: ' . $e->getMessage());

                return null;
            }

            $replayStatus = $replay->status;
            $failedBody = $replayStatus >= 400 ? Wire::cappedResponseBody($replay->body)[0] : null;
            $this->report($attemptId, $replayStatus, is_array($failedBody) ? $failedBody : null);

            return $replay;
        } catch (\Throwable) {
            return null;
        } finally {
            $this->notify(
                $capture,
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
    private function notify(Capture $capture, ?array $result, mixed $healStatus, ?int $replayStatus, int $healMs): void
    {
        $onHeal = $this->config->onHeal;
        if (!is_callable($onHeal)) {
            return;
        }
        try {
            $operations = $result['operations'] ?? null;
            $onHeal(new HealEvent(
                Wire::safeUrl($capture->url),
                $capture->status,
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
