<?php declare(strict_types=1);

namespace Mnfst;

use Mnfst\Hooks\Cake;
use Mnfst\Hooks\Curl;
use Mnfst\Hooks\Guzzle;
use Mnfst\Hooks\Symfony;
use Mnfst\Hooks\WordPress;

final class Manifest
{
    public const VERSION = '0.2.0';

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
        // A test suite fakes its HTTP: Http::fake(), Guzzle's MockHandler,
        // Symfony's MockHttpClient. Those faked 4xx go through the real client
        // and would be reported to Manifest as failures that never happened,
        // filling the dashboard and, with a real key, hitting the live project.
        // So manifest() installs nothing under a test runner unless MNFST_IN_TESTS
        // opts in (the SDK's own suite does). auto_prepend_file installs, which
        // cannot guard themselves in app code, are covered by this too.
        if (!self::captureEnabled()) {
            return;
        }

        $config = Config::resolve($apiKey, $url, $onHeal);
        // Refresh existing hooks even when the key was explicitly cleared.
        if ($config->apiKey === null && !self::$started) {
            return;
        }
        $api = new HealApi($config);

        // Every hook, not a short-circuit: each must get its chance to install.
        $installed = [
            Guzzle::install($config, $api),
            Cake::install($config, $api),
            Symfony::install($config, $api),
            WordPress::install($config, $api),
            Curl::install($config, $api),
        ];

        // Announce only what is real: without the extension nothing is
        // instrumented, and a handshake would make the dashboard show an app
        // as connected that can never report a failure. Once per process, so a
        // second manifest() call refreshes the hooks without announcing again.
        if (!self::$started && in_array(true, $installed, true)) {
            self::$started = true;
            (new Handshake($config))->announce();
        }
    }

    /** True when the opentelemetry extension is present, so full coverage is active. */
    public static function hasFullCoverage(): bool
    {
        return function_exists('OpenTelemetry\Instrumentation\hook');
    }

    /**
     * True when a PHPUnit or Pest run is in progress: their faked HTTP must not
     * be captured. Both constants are defined by the runner's own bootstrap, so
     * they are set during a test run and not merely because a framework
     * autoloaded a PHPUnit class (Laravel's artisan loads PHPUnit\Runner\Version
     * even under `serve`, which must stay instrumented).
     */
    public static function inTestRunner(): bool
    {
        if (defined('PHPUNIT_COMPOSER_INSTALL') || defined('PEST_VERSION')) {
            return true;
        }
        // auto_prepend_file runs before the runner defines its constants.
        $argv = $_SERVER['argv'] ?? [];
        $runner = basename($argv[0] ?? '');

        return in_array($runner, ['phpunit', 'phpunit.phar', 'pest', 'pest.phar'], true)
            || ($runner === 'artisan' && ($argv[1] ?? null) === 'test');
    }

    public static function captureEnabled(): bool
    {
        return !self::inTestRunner() || self::healsInTests();
    }

    /** Opt back in to healing during tests with MNFST_IN_TESTS=1 (integration tests against a real server). */
    private static function healsInTests(): bool
    {
        return filter_var((string) Config::env('MNFST_IN_TESTS'), FILTER_VALIDATE_BOOLEAN);
    }
}
