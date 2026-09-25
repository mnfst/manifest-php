<?php declare(strict_types=1);

namespace Mnfst;

/**
 * Verify an install from the project directory. It resolves the SDK version,
 * masks and validates the key against the handshake endpoint, and checks that
 * each framework the project uses has its adapter wired.
 */
final class Doctor
{
    public static function run(array $argv, Config $config, ?string $root = null): int
    {
        $failures = 0;

        self::line('Manifest ' . Manifest::VERSION . ' on PHP ' . PHP_VERSION);

        if ($config->apiKey === null) {
            self::line('  key        missing — set MNFST_KEY');
            $failures++;
        } else {
            self::line('  key        ' . self::mask($config->apiKey));
        }

        if ($config->apiKey !== null) {
            $failures += self::reportProject($config);
        }

        foreach (Frameworks::inspect($root ?? (string) getcwd()) as $framework) {
            self::line('  ' . str_pad($framework['name'], 10) . ' ' . $framework['line']);
            if (!$framework['ok']) {
                $failures++;
            }
        }
        self::line('  guzzle     your own clients: push \Mnfst\Guzzle\middleware() on their handler stack');
        self::line('  not seen   raw curl_* calls, file_get_contents, and Guzzle clients built inside libraries');

        return $failures > 0 ? 1 : 0;
    }

    private static function reportProject(Config $config): int
    {
        $answer = self::hello($config);
        if ($answer === null) {
            self::line('  server     unreachable at ' . $config->baseUrl);

            return 1;
        }

        [$status, $hello] = $answer;

        // CONTRACT: a 200 confirms the key; a 401, or a 403 whose body is not
        // project_disabled, rejects it. Reporting a rejected key as an
        // unreachable server sends the operator after the wrong problem.
        if ($status !== 200) {
            self::line($status === 403 && ($hello['error'] ?? null) === 'project_disabled'
                ? '  server     accepted the key, but healing is disabled for this project'
                : '  server     rejected the key (HTTP ' . $status . ') at ' . $config->baseUrl);

            return 1;
        }

        self::line('  server     accepted the key at ' . $config->baseUrl);

        return 0;
    }

    private static function mask(string $key): string
    {
        return strlen($key) <= 8 ? '****' : substr($key, 0, 4) . str_repeat('*', 8) . substr($key, -2);
    }

    /**
     * A key check, not an install: `probe` tells the server to answer without
     * recording a handshake, so a run from a laptop cannot mark the app as
     * connected.
     *
     * @return array{0: int, 1: array|null}|null status and body, or null if unreachable
     */
    private static function hello(Config $config): ?array
    {
        $ch = curl_init($config->baseUrl . '/v1/hello');
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['runtime' => 'php-' . PHP_VERSION, 'probe' => true]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: mnfst-php/' . Manifest::VERSION,
                'Authorization: Bearer ' . $config->apiKey,
            ],
            CURLOPT_TIMEOUT => Config::HELLO_TIMEOUT_SECONDS,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if (!is_string($raw) || $status === 0) {
            return null;   // no HTTP response at all: genuinely unreachable
        }
        $body = json_decode($raw, true);

        return [$status, is_array($body) ? $body : null];
    }

    private static function line(string $text): void
    {
        echo $text . PHP_EOL;
    }
}
