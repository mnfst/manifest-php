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
        $api = new HealApi($config);

        Guzzle::install($config, $api);
        Cake::install($config, $api);
        Curl::install($config, $api);

        (new Handshake($config))->announce();
    }

    /** True when the opentelemetry extension is present, so full coverage is active. */
    public static function hasFullCoverage(): bool
    {
        return function_exists('OpenTelemetry\Instrumentation\hook');
    }
}
