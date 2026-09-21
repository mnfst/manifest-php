<?php declare(strict_types=1);

namespace Mnfst\Hooks;

use Mnfst\Capture;
use Mnfst\Config;
use Mnfst\Gate;
use Mnfst\HealApi;
use Mnfst\Healer;
use Mnfst\Wire;

use function OpenTelemetry\Instrumentation\hook;

/** Raw curl is capture-only: the extension cannot replace an internal function's return value. */
final class Curl
{
    private static bool $installed = false;
    private static ?Healer $healer = null;

    /** @var \WeakMap<\CurlHandle, array{body: ?string, oversized: bool, headers: array<string, list<string>>}> */
    private static ?\WeakMap $requests = null;

    public static function install(Config $config, HealApi $api): bool
    {
        if (!function_exists('curl_exec') || !function_exists('OpenTelemetry\Instrumentation\hook')) {
            return false;
        }
        self::$healer = new Healer($config, $api);
        self::$requests ??= new \WeakMap();
        if (self::$installed) {
            return false;
        }
        self::$installed = true;

        hook(null, 'curl_setopt', post: static function (mixed $obj, array $params, mixed $result): void {
            if ($result === true) {
                self::remember($params[0] ?? null, [$params[1] => $params[2]]);
            }
        });
        hook(null, 'curl_setopt_array', post: static function (mixed $obj, array $params, mixed $result): void {
            if ($result === true) {
                self::remember($params[0] ?? null, $params[1] ?? []);
            }
        });
        hook(null, 'curl_copy_handle', post: static function (mixed $obj, array $params, mixed $copy): void {
            if ($copy instanceof \CurlHandle && isset(self::$requests[$params[0]])) {
                self::$requests[$copy] = self::$requests[$params[0]];
            }
        });
        hook(null, 'curl_reset', post: static function (mixed $obj, array $params): void {
            $handle = $params[0] ?? null;
            if ($handle instanceof \CurlHandle && self::$requests !== null) {
                self::$requests->offsetUnset($handle);
            }
        });
        hook(null, 'curl_exec', post: static function (mixed $obj, array $params, mixed $result): void {
            self::observe($params[0] ?? null, $result);
        });

        return true;
    }

    private static function remember(mixed $handle, array $options): void
    {
        if (!$handle instanceof \CurlHandle || HealApi::isInternalCall()) {
            return;
        }
        $request = self::$requests[$handle] ?? ['body' => null, 'oversized' => false, 'headers' => []];
        if (array_key_exists(CURLOPT_POSTFIELDS, $options)) {
            $body = $options[CURLOPT_POSTFIELDS];
            $request['oversized'] = !is_string($body) || strlen($body) > Gate::REQUEST_BODY_LIMIT;
            $request['body'] = $request['oversized'] ? null : $body;
        }
        if (array_key_exists(CURLOPT_HTTPHEADER, $options)) {
            $request['headers'] = [];
            foreach ((array) $options[CURLOPT_HTTPHEADER] as $line) {
                if (is_string($line) && str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $request['headers'][trim($name)] = [trim($value)];
                }
            }
        }
        self::$requests[$handle] = $request;
    }

    /** The higher-level hook owns these calls, preventing duplicate captures. */
    private static function ownedByAManagedClient(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 32) as $frame) {
            $class = $frame['class'] ?? '';
            foreach (['GuzzleHttp\\', 'Cake\\Http\\Client', 'Symfony\\Component\\HttpClient', 'WpOrg\\Requests'] as $managed) {
                if (str_starts_with($class, $managed)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function observe(mixed $handle, mixed $result): void
    {
        try {
            if (!$handle instanceof \CurlHandle || self::$healer === null || HealApi::isInternalCall()) {
                return;
            }
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if (!Gate::shouldCapture($status) || self::ownedByAManagedClient()) {
                return;
            }
            $request = self::$requests[$handle] ?? ['body' => null, 'oversized' => false, 'headers' => []];
            self::$healer->attempt(new Capture(
                (string) (curl_getinfo($handle, CURLINFO_EFFECTIVE_METHOD) ?: 'GET'),
                (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL),
                $request['headers'],
                $request['body'],
                $request['oversized'],
                $status,
                is_string($result) ? substr($result, 0, Wire::RESPONSE_BODY_CAP + 1) : '',
                microtime(true) - (float) curl_getinfo($handle, CURLINFO_TOTAL_TIME),
            ), null);
        } catch (\Throwable) {
            // Observation must never affect the caller.
        }
    }
}
