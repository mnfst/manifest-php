<?php declare(strict_types=1);

namespace Mnfst;

use Mnfst\Hooks\Cake;
use Mnfst\Hooks\Curl;
use Mnfst\Hooks\Guzzle;

final class Manifest
{
    public const VERSION = '0.1.0';

    private static bool $started = false;

    public static function start(?string $apiKey = null, ?string $url = null, ?callable $onHeal = null): void
    {
        if (self::$started) {
            trigger_error('manifest() was already called; the second call is ignored', E_USER_WARNING);

            return;
        }
        self::$started = true;

        $config = Config::resolve($apiKey, $url, $onHeal);
        if ($config->apiKey === null) {
            return;   // inert without a key; `manifest doctor` says so
        }
        $api = new HealApi($config);

        // Every hook, not a short-circuit: each must get its chance to install.
        $installed = [Guzzle::install($config, $api), Cake::install($config, $api), Curl::install($config, $api)];

        // Announce only what is real: without the extension nothing is
        // instrumented, and a handshake would make the dashboard show an app
        // as connected that can never report a failure.
        if (in_array(true, $installed, true)) {
            (new Handshake($config))->announce();
        }
    }

    /** True when the opentelemetry extension is present, so full coverage is active. */
    public static function hasFullCoverage(): bool
    {
        return function_exists('OpenTelemetry\Instrumentation\hook');
    }
}
