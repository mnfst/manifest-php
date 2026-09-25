<?php declare(strict_types=1);

namespace Mnfst;

/**
 * Which frameworks a project uses and whether the Manifest adapter is wired
 * into each, read from the files on disk. The doctor runs in its own
 * process, so it cannot see what the app loads at runtime.
 */
final class Frameworks
{
    private const CAKE_FLOOR = '5.1.0';

    /** @return list<array{name: string, ok: bool, line: string}> */
    public static function inspect(string $root): array
    {
        $composer = self::json($root . '/composer.json');
        $packages = array_merge((array) ($composer['require'] ?? []), (array) ($composer['require-dev'] ?? []));
        $found = [];

        if (isset($packages['laravel/framework'])) {
            $off = (array) ($composer['extra']['laravel']['dont-discover'] ?? []);
            $found[] = in_array('*', $off, true) || in_array('mnfst/manifest-php', $off, true)
                ? self::missing('laravel', 'package discovery is off for mnfst/manifest-php: add Mnfst\Laravel\ManifestServiceProvider to bootstrap/providers.php')
                : self::wired('laravel', 'service provider auto-discovered');
        }

        if (isset($packages['cakephp/cakephp'])) {
            $version = self::lockedVersion($root, 'cakephp/cakephp');
            if ($version !== null && version_compare($version, self::CAKE_FLOOR, '<')) {
                $found[] = self::missing('cake', "CakePHP {$version} is not supported: the adapter needs CakePHP 5.1+");
            } else {
                $found[] = self::mentions($root . '/src/Application.php', 'ManifestPlugin') || self::mentions($root . '/config/bootstrap.php', 'Mnfst\Cake')
                    ? self::wired('cake', 'ManifestPlugin')
                    : self::missing('cake', 'add $this->addPlugin(\Mnfst\Cake\ManifestPlugin::class); to Application::bootstrap()');
            }
        }

        if (isset($packages['symfony/framework-bundle'])) {
            $found[] = self::mentions($root . '/config/bundles.php', 'ManifestBundle')
                ? self::wired('symfony', 'ManifestBundle')
                : self::missing('symfony', "add Mnfst\\Symfony\\ManifestBundle::class => ['all' => true] to config/bundles.php");
        }

        if (self::isWordPress($root)) {
            $found[] = self::wordPressWired($root)
                ? self::wired('wordpress', 'must-use plugin')
                : self::missing('wordpress', 'add wp-content/mu-plugins/manifest.php (see the README)');
        }

        return $found;
    }

    /** @return array{name: string, ok: bool, line: string} */
    private static function wired(string $name, string $how): array
    {
        return ['name' => $name, 'ok' => true, 'line' => "wired ({$how})"];
    }

    /** @return array{name: string, ok: bool, line: string} */
    private static function missing(string $name, string $what): array
    {
        return ['name' => $name, 'ok' => false, 'line' => "not wired: {$what}"];
    }

    private static function mentions(string $file, string $needle): bool
    {
        return is_file($file) && str_contains((string) @file_get_contents($file), $needle);
    }

    private static function isWordPress(string $root): bool
    {
        foreach (['', '/web', '/public'] as $dir) {
            if (is_file($root . $dir . '/wp-config.php') || is_file($root . $dir . '/wp-load.php')) {
                return true;
            }
        }

        return false;
    }

    private static function wordPressWired(string $root): bool
    {
        foreach (['/wp-content/mu-plugins', '/web/app/mu-plugins', '/public/wp-content/mu-plugins'] as $dir) {
            foreach (glob($root . $dir . '/*.php') ?: [] as $file) {
                if (self::mentions($file, 'Mnfst\WordPress')) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function lockedVersion(string $root, string $package): ?string
    {
        foreach ((array) (self::json($root . '/composer.lock')['packages'] ?? []) as $locked) {
            if (is_array($locked) && ($locked['name'] ?? null) === $package && is_string($locked['version'] ?? null)) {
                return ltrim($locked['version'], 'v');
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function json(string $file): array
    {
        $decoded = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
