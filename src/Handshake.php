<?php declare(strict_types=1);

namespace Mnfst;

/**
 * Announce the install once, not once per web request.
 *
 * Node and Python announce once per process. PHP starts a new request state
 * per request, so the same code announces on every request — measured at 10
 * calls for 10 requests. A marker file with a TTL brings that back to 1.
 *
 * Best effort and fire-and-forget: a handshake that fails is never retried and
 * never surfaces to the app. Absence of a handshake is the signal the dashboard
 * reads as "not connected".
 */
final class Handshake
{
    public const TTL_SECONDS = 3600;

    public function __construct(private readonly Config $config)
    {
    }

    public function markerPath(): string
    {
        $fingerprint = substr(hash('sha256', $this->config->baseUrl . '|' . ($this->config->apiKey ?? '')), 0, 16);

        return sys_get_temp_dir() . '/mnfst-hello-' . $fingerprint;
    }

    public function announce(): void
    {
        try {
            if ($this->config->apiKey === null || $this->isFresh()) {
                return;   // no key: the install is not connected to any project, there is nothing to announce
            }
            @touch($this->markerPath());
            $this->send();
        } catch (\Throwable) {
            // never surfaces to the app
        }
    }

    private function isFresh(): bool
    {
        $marker = $this->markerPath();
        if (!is_file($marker)) {
            return false;
        }

        return (time() - (int) @filemtime($marker)) < self::TTL_SECONDS;
    }

    private function send(): void
    {
        $headers = [
            'Content-Type: application/json',
            'User-Agent: mnfst-php/' . Manifest::VERSION,
            'Authorization: Bearer ' . $this->config->apiKey,
        ];

        HealApi::withInternalCall(function () use ($headers): void {
            $ch = curl_init($this->config->baseUrl . '/v1/hello');
            if ($ch === false) {
                return;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['runtime' => 'php-' . PHP_VERSION]),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => Config::HELLO_TIMEOUT_SECONDS,
                CURLOPT_CONNECTTIMEOUT => Config::HELLO_TIMEOUT_SECONDS,
            ]);
            curl_exec($ch);
        });
    }
}
