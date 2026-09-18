<?php declare(strict_types=1);

namespace Mnfst;

/**
 * The SDK's own calls to Manifest. Everything here fails soft: a heal that
 * cannot complete returns null and the caller serves the original response.
 */
final class HealApi
{
    /**
     * An attempt Manifest opened that we never replayed. Reported so the
     * attempt ledger closes instead of holding open an answer that never comes.
     */
    public const NOT_ATTEMPTED = 'replay_not_attempted';

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
        return microtime(true) >= $this->disabledUntil;
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

    public function reportOutcome(
        string $healAttemptId,
        ?int $retryStatusCode,
        ?array $retryBody = null,
        ?string $error = null,
    ): void {
        if ($error !== null) {
            $body = ['failure' => [
                'kind' => $error === self::NOT_ATTEMPTED ? 'not_attempted' : 'transport_error',
                'message' => $error,
            ]];
        } else {
            $response = ['statusCode' => $retryStatusCode];
            if ($retryBody !== null) {
                $response['body'] = $retryBody;
                $response['truncated'] = false;
            }
            $body = ['response' => $response];
        }

        $this->send('PATCH', '/v1/heal-attempts/' . rawurlencode($healAttemptId), $body, Config::HEAL_TIMEOUT_SECONDS);
    }

    /** @return array{0: int, 1: string}|null status and raw body, or null on any failure */
    private function send(string $method, string $path, array $body, int $timeout): ?array
    {
        $headers = ['Content-Type: application/json', 'User-Agent: mnfst-php/' . Manifest::VERSION];
        if ($this->config->apiKey !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->config->apiKey;
        }

        // An upstream error body is whatever bytes the server sent: Latin-1
        // pages, binary, a cut multibyte character. Substituting the invalid
        // bytes keeps the capture; json_encode returning false would have sent
        // an empty payload and lost it.
        $json = json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (!is_string($json)) {
            return null;
        }

        return self::withInternalCall(function () use ($method, $path, $json, $timeout, $headers): ?array {
            $ch = curl_init($this->config->baseUrl . $path);
            if ($ch === false) {
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_POSTFIELDS => $json,
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

        return is_array($body)
            && (($body['error'] ?? null) === 'project_disabled' || ($body['status'] ?? null) === 'app_disabled');
    }
}
