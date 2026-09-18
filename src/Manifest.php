<?php declare(strict_types=1);

namespace Mnfst;

use Mnfst\Hooks\Cake;
use Mnfst\Hooks\Curl;
use Mnfst\Hooks\Guzzle;

final class Manifest
{
    public const VERSION = '0.1.0';

    private static bool $started = false;

    /**
     * Idempotent: frameworks boot more than once per process (Laravel's test
     * runner boots the app for every test, and turns any PHP warning into an
     * exception), and auto_prepend_file plus a bootstrap call is a common
     * double. The hooks are process-global and installed once; a later call
     * only refreshes the configuration they use.
     */
    public static function start(?string $apiKey = null, ?string $url = null, ?callable $onHeal = null): void
    {
        $config = Config::resolve($apiKey, $url, $onHeal);
        $api = new HealApi($config);

        Guzzle::install($config, $api);
        Cake::install($config, $api);
        Curl::install($config, $api);

        if (!self::$started) {
            self::$started = true;
            (new Handshake($config))->announce();
        }
    }

    /** True when the opentelemetry extension is present, so full coverage is active. */
    public static function hasFullCoverage(): bool
    {
        return function_exists('OpenTelemetry\Instrumentation\hook');
    }
}
