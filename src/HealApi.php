<?php declare(strict_types=1);

namespace Mnfst;

/**
 * The SDK's own calls to Manifest. Everything here fails soft: a heal that
 * cannot complete returns null and the caller serves the original response.
 * Without a project key nothing is sent at all — an unkeyed call could only
 * be refused, and it would carry a captured request off the host for nothing.
 */
final class HealApi
{
    /**
     * An attempt Manifest opened that we never replayed. Reported so the
     * attempt ledger closes instead of holding open an answer that never comes.
     */
    public const NOT_ATTEMPTED = 'replay_not_attempted';

    /** The server's cap on a failure message, in UTF-8 bytes. */
    public const FAILURE_MESSAGE_CAP = 512;

    private const DISABLED_BACKOFF_SECONDS = 300;
    private const MAX_HEAL_RESPONSE = 1048576;

    private static bool $internalCall = false;
    private float $disabledUntil = 0.0;

    public function __construct(private readonly Config $config)
    {
    }

    /** True while the SDK is making its own call, so hooks can skip it. */
    public static function isInternalCall(): bool
    {
        return self::$internalCall;
    }

    public static function withInternalCall(callable $fn): mixed
    {
        $previous = self::$internalCall;
        self::$internalCall = true;
        try {
            return $fn();
        } finally {
            self::$internalCall = $previous;
        }
    }

    public function healingEnabled(): bool
    {
        return $this->config->apiKey !== null && microtime(true) >= $this->disabledUntil;
    }

    public function heal(array $payload): ?array
    {
        if (!$this->healingEnabled()) {
            return null;
        }

        $response = $this->send('POST', '/v1/heal', $payload, Config::HEAL_TIMEOUT_SECONDS);
        if ($response === null) {
            return null;
        }

        [$status, $raw] = $response;

        if ($status === 403 && $this->isProjectDisabled($raw)) {
            $this->disabledUntil = microtime(true) + self::DISABLED_BACKOFF_SECONDS;

            return null;
        }

        if ($status !== 200 || strlen($raw) > self::MAX_HEAL_RESPONSE) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The retry got an HTTP answer. A failure carries its raw body so the
     * server can tell a recurrence from a newly revealed issue; a success
     * carries the status alone.
     */
    public function reportResponse(string $healAttemptId, int $statusCode, mixed $body = null, bool $truncated = false): void
    {
        $response = ['statusCode' => $statusCode];
        if ($body !== null) {
            $response['body'] = $body;
            $response['truncated'] = $truncated;
        }
        $this->report($healAttemptId, ['response' => $response]);
    }

    /** The retry got no HTTP answer, or was never sent. Inconclusive evidence either way. */
    public function reportFailure(string $healAttemptId, string $kind, string $message): void
    {
        $this->report($healAttemptId, ['failure' => ['kind' => $kind, 'message' => self::safeMessage($message)]]);
    }

    /** Masked like a URL on the wire, then cut at the cap without splitting a character. */
    public static function safeMessage(string $message): string
    {
        $masked = preg_replace_callback(
            '~https?://[^\s"\'()<>]+~i',
            static fn (array $m): string => Wire::safeUrl($m[0]),
            $message,
        ) ?? $message;

        return mb_strcut($masked, 0, self::FAILURE_MESSAGE_CAP, 'UTF-8');
    }

    private function report(string $healAttemptId, array $body): void
    {
        if ($this->config->apiKey === null) {
            return;
        }
        $this->send('PATCH', '/v1/heal-attempts/' . rawurlencode($healAttemptId), $body, Config::REPORT_TIMEOUT_SECONDS);
    }

    /** @return array{0: int, 1: string}|null status and raw body, or null on any failure */
    private function send(string $method, string $path, array $body, int $timeout): ?array
    {
        $headers = [
            'Content-Type: application/json',
            'User-Agent: mnfst-php/' . Manifest::VERSION,
            'Authorization: Bearer ' . $this->config->apiKey,
        ];

        return self::withInternalCall(function () use ($method, $path, $body, $timeout, $headers): ?array {
            $ch = curl_init($this->config->baseUrl . $path);
            if ($ch === false) {
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_POSTFIELDS => json_encode($body),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            return is_string($raw) && $status > 0 ? [$status, $raw] : null;
        });
    }

    private function isProjectDisabled(string $raw): bool
    {
        $body = json_decode($raw, true);

        return is_array($body) && ($body['error'] ?? null) === 'project_disabled';
    }
}
