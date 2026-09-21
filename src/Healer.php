<?php declare(strict_types=1);

namespace Mnfst;

/**
 * One captured failure, start to finish: heal, plan the retry, replay once,
 * report, notify. Shared by every client hook so they cannot drift apart; a
 * hook only knows how to describe its call as a Capture and how to send the
 * planned retry with the client that made the call.
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
     * @param (callable(array{url: string, headers: array<string, ?string>, body: ?string}): Outcome)|null $send
     *        replays through the original client; null for capture-only clients
     * @return \Mnfst\Outcome|null the retry, or null to keep the original response
     */
    public function attempt(Capture $capture, ?callable $send): ?Outcome
    {
        if (!Manifest::captureEnabled() || !$this->api->healingEnabled() || !Gate::shouldCapture($capture->status)) {
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
            $attemptId = is_string($result['healAttemptId'] ?? null) ? $result['healAttemptId'] : null;

            $plan = $send === null ? null : Replay::plan($capture->method, $capture->url, $body, $replayable, $contentType, $result, $capture->headers);
            if ($plan === null) {
                if ($attemptId !== null) {
                    $this->api->reportFailure($attemptId, 'not_attempted', HealApi::NOT_ATTEMPTED);
                }

                return null;
            }

            $replayAttempted = true;
            try {
                $outcome = HealApi::withInternalCall(static fn (): Outcome => $send($plan));
            } catch (\Throwable $e) {
                if ($attemptId !== null) {
                    $this->api->reportFailure($attemptId, 'transport_error', $e->getMessage());
                }

                return null;
            }

            $replayStatus = $outcome->status;
            if ($attemptId !== null) {
                $this->reportOutcome($attemptId, $outcome);
            }

            return $outcome;
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

    /** A failed retry carries its raw body so the server can tell a recurrence from a new issue. */
    private function reportOutcome(string $attemptId, Outcome $outcome): void
    {
        if ($outcome->status < 400) {
            $this->api->reportResponse($attemptId, $outcome->status);

            return;
        }
        [$body, $truncated] = Wire::cappedResponseBody($outcome->body);
        $this->api->reportResponse($attemptId, $outcome->status, $body, $truncated);
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
            // Fail open: the SDK must never break the app, so an onHeal that
            // throws is swallowed. A warning here would escape attempt() in
            // apps that turn warnings into exceptions (the Laravel case).
            error_log('manifest: the onHeal callback threw');
        }
    }
}
