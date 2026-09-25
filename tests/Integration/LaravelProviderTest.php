<?php declare(strict_types=1);

namespace Mnfst\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Mnfst\Config;
use Mnfst\Handshake;
use Mnfst\HealApi;
use Mnfst\Laravel\ManifestServiceProvider;
use Mnfst\Tests\Support\StubManifest;
use Mnfst\Tests\Support\StubUpstream;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

use function Mnfst\Guzzle\middleware;

/**
 * The provider the way Laravel runs it: registered, then booted, with the
 * Http facade resolving its factory from the container.
 */
#[RunTestsInSeparateProcesses]
final class LaravelProviderTest extends TestCase
{
    private StubManifest $manifest;
    private StubUpstream $upstream;
    private Container $app;

    protected function setUp(): void
    {
        $this->manifest = new StubManifest();
        $this->manifest->start();
        $this->upstream = new StubUpstream();
        $this->upstream->start();
        $this->manifest->setResult(['status' => 'patched', 'healAttemptId' => 'a1', 'healedRequest' => ['body' => ['limit' => 100]]]);
        $config = Config::resolve('k', $this->manifest->url);
        @unlink((new Handshake($config))->markerPath());
        @unlink((new HealApi($config))->backoffPath());

        $this->app = new Container();
        Container::setInstance($this->app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->app->singleton(Factory::class);
        // config/services.php: 'manifest' => ['key' => env('MNFST_KEY'), 'url' => env('MNFST_URL')]
        $values = ['services.manifest.key' => 'k', 'services.manifest.url' => $this->manifest->url];
        $this->app->instance('config', new class ($values) {
            public function __construct(private readonly array $values)
            {
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->values[$key] ?? $default;
            }
        });
    }

    protected function tearDown(): void
    {
        $this->manifest->stop();
        $this->upstream->stop();
    }

    private function boot(): void
    {
        $provider = new ManifestServiceProvider($this->app);
        $provider->register();
        $provider->boot();
    }

    public function testHealsAnHttpFacadeCallWithNoAppCode(): void
    {
        $this->boot();

        $response = Http::post($this->upstream->url . '/orders', ['limit' => 500]);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('"limit":100', $response->body());
        self::assertCount(1, $this->manifest->heals());
        self::assertSame([['a1', ['response' => ['statusCode' => 200]]]], $this->manifest->outcomes());
        self::assertCount(1, $this->manifest->hellos(), 'boot() started the SDK and the adapter is registered');
    }

    public function testAFactoryResolvedBeforeRegisterStillHeals(): void
    {
        $this->app->make(Factory::class);
        $this->boot();

        self::assertSame(200, Http::post($this->upstream->url . '/orders', ['limit' => 500])->status());
    }

    public function testRegisteringTwiceHealsOnce(): void
    {
        $this->boot();
        $this->boot();

        Http::post($this->upstream->url . '/orders', ['limit' => 500]);

        self::assertCount(1, $this->manifest->heals());
    }

    public function testAManualMiddlewareOnTopOfTheProviderHealsOnce(): void
    {
        $this->boot();

        Http::withMiddleware(middleware())->post($this->upstream->url . '/orders', ['limit' => 500]);

        self::assertCount(1, $this->manifest->heals());
    }

    public function testCallsNotHealedAreTracked(): void
    {
        $this->boot();
        Http::get($this->upstream->url . '/ping');

        $config = Config::resolve('k', $this->manifest->url);
        (new \Mnfst\Tracking($config, new HealApi($config)))->flush();

        self::assertSame(['/ping'], array_map(
            fn (array $call): string => substr($call['url'], strlen($this->upstream->url)),
            $this->manifest->tracked(),
        ));
    }
}
