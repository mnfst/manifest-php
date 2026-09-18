<?php declare(strict_types=1);

namespace Mnfst\Hooks;

use Mnfst\Config;
use Mnfst\Gate;
use Mnfst\HealApi;
use Mnfst\Wire;

use function OpenTelemetry\Instrumentation\hook;

/**
 * Observe raw curl_exec traffic. Capture only — never heal.
 *
 * The extension can observe an internal function but cannot replace its return
 * value, verified against strtoupper and against the real stripe/stripe-php
 * package. So a library with its own curl client gets its failures recorded in
 * the dashboard while the application keeps the original error. This is a
 * permanent limit of PHP, not a temporary one.
 *
 * Because it cannot replay, an attempt the server opens for one of its
 * captures is closed as not attempted; the payload carries nothing that would
 * let the server tell a curl capture from a healable one.
 */
final class Curl
{
    private static bool $installed = false;

    /** @var array{config: Config, api: HealApi}|null */
    private static ?array $deps = null;

    /** @var array<int, string> the request body per curl handle, keyed by object id */
    private static array $bodies = [];

    /** @var array<int, array<string, string>> the request headers per curl handle, keyed by object id */
    private static array $headers = [];

    public static function install(Config $config, HealApi $api): bool
    {
        if (!function_exists('OpenTelemetry\Instrumentation\hook')) {
            return false;
        }
        self::$deps = ['config' => $config, 'api' => $api];
        if (self::$installed) {
            return false;
        }
        self::$installed = true;

        // curl_exec alone cannot show the request body, so record it as it is
        // set. Both setters must be hooked: curl_setopt_array does not call
        // curl_setopt.
        hook(null, 'curl_setopt', pre: static function (mixed $obj, array $params) {
            if (($params[1] ?? null) === CURLOPT_POSTFIELDS) {
                self::remember($params[0] ?? null, $params[2] ?? null);
            }
            if (($params[1] ?? null) === CURLOPT_HTTPHEADER) {
                self::rememberHeaders($params[0] ?? null, $params[2] ?? null);
            }
        });

        hook(null, 'curl_setopt_array', pre: static function (mixed $obj, array $params) {
            $options = $params[1] ?? null;
            if (is_array($options) && array_key_exists(CURLOPT_POSTFIELDS, $options)) {
                self::remember($params[0] ?? null, $options[CURLOPT_POSTFIELDS]);
            }
            if (is_array($options) && array_key_exists(CURLOPT_HTTPHEADER, $options)) {
                self::rememberHeaders($params[0] ?? null, $options[CURLOPT_HTTPHEADER]);
            }
        });

        hook(null, 'curl_exec', post: static function (mixed $obj, array $params, mixed $result, ?\Throwable $e) {
            self::observe($params[0] ?? null, $result);
        });

        return true;
    }

    private static function remember(mixed $handle, mixed $body): void
    {
        if (is_object($handle) && is_string($body)) {
            self::$bodies[spl_object_id($handle)] = $body;
        }
    }

    /** CURLOPT_HTTPHEADER is a list of `Name: value` lines; the server needs them as a map (masked on the wire). */
    private static function rememberHeaders(mixed $handle, mixed $lines): void
    {
        if (!is_object($handle) || !is_array($lines)) {
            return;
        }
        $headers = [];
        foreach ($lines as $line) {
            if (!is_string($line) || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[trim($name)] = trim($value);
        }
        self::$headers[spl_object_id($handle)] = $headers;
    }

    /**
     * Guzzle and CakePHP both run on curl underneath, so without this the same
     * failure is reported twice: once by the client's own hook, which heals it,
     * and again here, which cannot. Only the client's hook should report it.
     *
     * The walk is bounded and only runs on a captured 4xx, which is rare.
     */
    private static function ownedByAManagedClient(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16) as $frame) {
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
            if (!is_object($handle) || self::$deps === null || HealApi::isInternalCall()) {
                return;
            }
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if (!Gate::shouldCapture($status) || self::ownedByAManagedClient()) {
                return;
            }

            $raw = self::$bodies[spl_object_id($handle)] ?? null;
            $body = $raw === null ? null : Gate::parseJsonBody($raw);

            // curl_exec's return value IS the response body when
            // CURLOPT_RETURNTRANSFER is set. When it is not, the body went
            // straight to output and only the status is available.
            [$responseBody, $truncated] = is_string($result)
                ? Wire::cappedResponseBody($result)
                : [null, false];

            $api = self::$deps['api'];
            $result = $api->heal(Wire::healPayload(
                bin2hex(random_bytes(16)),
                (string) (curl_getinfo($handle, CURLINFO_EFFECTIVE_METHOD) ?: 'GET'),
                (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL),
                self::$headers[spl_object_id($handle)] ?? [],
                $body,
                $status,
                $responseBody,
                $truncated,
                (int) round(((float) curl_getinfo($handle, CURLINFO_TOTAL_TIME)) * 1000),
            ));

            // Raw curl cannot replay. An attempt the server opened anyway
            // must be closed, or its ledger waits for an answer that never comes.
            $attemptId = is_array($result) ? ($result['healAttemptId'] ?? null) : null;
            if (is_string($attemptId)) {
                $api->reportOutcome($attemptId, null, null, HealApi::NOT_ATTEMPTED);
            }
        } catch (\Throwable) {
            // observation must never affect the caller
        } finally {
            if (is_object($handle)) {
                unset(self::$bodies[spl_object_id($handle)], self::$headers[spl_object_id($handle)]);
            }
        }
    }
}
