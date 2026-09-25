<?php declare(strict_types=1);

namespace Mnfst\Laravel;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use Mnfst\Guzzle\Middleware;
use Mnfst\Manifest;

/**
 * Found by Composer package discovery (composer.json extra.laravel), so a
 * Laravel app writes no code: every Http:: call gets the Guzzle middleware,
 * and the SDK starts with the key from config('services.manifest') or
 * MNFST_KEY. artisan boots providers too, so console commands are covered.
 */
final class ManifestServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(Factory::class, static function (Factory $factory): void {
            self::attach($factory);
        });
        if ($this->app->resolved(Factory::class)) {
            self::attach($this->app->make(Factory::class));
        }
    }

    public function boot(): void
    {
        $config = $this->app->bound('config') ? $this->app->make('config') : null;
        Manifest::start(
            self::string($config?->get('services.manifest.key')),
            self::string($config?->get('services.manifest.url')),
        );
    }

    /** A factory can be resolved, or the provider registered, more than once; it gets the middleware once. */
    private static function attach(Factory $factory): void
    {
        static $attached = null;
        $attached ??= new \WeakMap();
        if (isset($attached[$factory])) {
            return;
        }
        $attached[$factory] = true;
        $factory->globalMiddleware(Middleware::create());
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
