<?php declare(strict_types=1);

namespace Mnfst;

final class Manifest
{
    public const VERSION = '0.6.1'; // x-release-please-version

    private static ?Config $config = null;

    private static ?Healer $healer = null;

    /** @var array<string, true> */
    private static array $adapters = [];

    private static bool $announced = false;

    /**
     * Idempotent: frameworks boot more than once per process (Laravel's test
     * runner boots the app for every test, and turns any PHP warning into an
     * exception). A later call only refreshes the configuration the adapters
     * use; an empty key turns them off.
     *
     * @param list<string>|string|null $allowlist only these calls reach Manifest: domains or domain/paths (default MNFST_ALLOWLIST)
     * @param list<string>|string|null $denylist these calls never reach Manifest, same entries (default MNFST_DENYLIST)
     */
    public static function start(
        ?string $apiKey = null,
        ?string $url = null,
        ?callable $onHeal = null,
        array|string|null $allowlist = null,
        array|string|null $denylist = null,
    ): void {
        // A test suite fakes its HTTP: Http::fake(), Guzzle's MockHandler,
        // Symfony's MockHttpClient. Those faked 4xx go through the real client
        // and would be reported to Manifest as failures that never happened,
        // filling the dashboard and, with a real key, hitting the live project.
        // So nothing starts under a test runner unless MNFST_IN_TESTS opts in
        // (the SDK's own suite does).
        if (!self::captureEnabled()) {
            return;
        }

        $config = Config::resolve($apiKey, $url, $onHeal, $allowlist, $denylist);
        if ($config->apiKey === null) {
            self::$healer = null;

            return;
        }
        if ($config->ignoredEntries !== []) {
            // error_log, not a warning: a test runner turns warnings into exceptions (see above).
            error_log('manifest: ignoring unreadable allowlist/denylist entries: ' . implode(', ', $config->ignoredEntries));
        }
        self::$config = $config;
        self::$healer = new Healer($config, new HealApi($config));
        self::announce();
    }

    /** The healer adapters use, or null when they must pass calls through. */
    public static function healer(): ?Healer
    {
        return self::captureEnabled() ? self::$healer : null;
    }

    /** An adapter is in place: once the SDK is started too, the install is real. */
    public static function register(string $adapter): void
    {
        self::$adapters[$adapter] = true;
        self::announce();
    }

    /** @return list<string> */
    public static function adapters(): array
    {
        return array_keys(self::$adapters);
    }

    /**
     * Announce only what is real: without an adapter nothing can report, and
     * a handshake would make the dashboard show an app as connected that can
     * never report a failure. Once per process.
     */
    private static function announce(): void
    {
        if (self::$announced || self::$healer === null || self::$config === null || self::$adapters === []) {
            return;
        }
        self::$announced = true;
        (new Handshake(self::$config))->announce();
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
        // A framework can boot before the runner defines its constants.
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
