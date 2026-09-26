<?php declare(strict_types=1);

namespace Mnfst\Tests\Integration;

use Cake\Http\Client as CakeClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use Mnfst\Config;
use Mnfst\HealApi;
use Mnfst\Manifest;
use Mnfst\Response\LazyHealingResponse;
use Mnfst\Symfony\HealingHttpClient;
use Mnfst\Tests\Support\StubManifest;
use Mnfst\Tests\Support\StubUpstream;
use Mnfst\Tracking;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\NativeHttpClient;

use function Mnfst\Cake\listen;
use function Mnfst\Guzzle\middleware;

/**
 * A call excluded by the denylist never reaches Manifest, through any adapter:
 * not healed, not tracked, and the app gets the original response.
 */
#[RunTestsInSeparateProcesses]
final class UrlFilterHookTest extends TestCase
{
    private StubManifest $manifest;
    private StubUpstream $upstream;

    protected function setUp(): void
    {
        $this->manifest = new StubManifest();
        $this->manifest->start();
        $this->upstream = new StubUpstream();
        $this->upstream->start();
        Manifest::start('k', $this->manifest->url, denylist: $this->host());
    }

    protected function tearDown(): void
    {
        $tracking = $this->tracking();
        foreach (glob($tracking->spoolPath() . '*') ?: [] as $file) {
            @unlink($file);
        }
        @unlink($tracking->sentPath());
        $this->manifest->stop();
        $this->upstream->stop();
    }

    private function host(): string
    {
        return (string) parse_url($this->upstream->url, PHP_URL_HOST);
    }

    private function tracking(): Tracking
    {
        $config = Config::resolve('k', $this->manifest->url);

        return new Tracking($config, new HealApi($config));
    }

    private function guzzle(): GuzzleClient
    {
        $stack = HandlerStack::create();
        $stack->push(middleware());

        return new GuzzleClient(['handler' => $stack, 'http_errors' => false]);
    }

    private function assertNothingReachedManifest(): void
    {
        $this->tracking()->flush();
        self::assertSame([], $this->manifest->tracked());
        self::assertSame([], $this->manifest->heals());
    }

    public function testGuzzle(): void
    {
        self::assertSame(200, $this->guzzle()->get($this->upstream->url . '/ping')->getStatusCode());
        self::assertSame(400, $this->guzzle()->post($this->upstream->url . '/orders', ['json' => ['limit' => 500]])->getStatusCode());
        $this->assertNothingReachedManifest();
    }

    public function testADeniedRouteLeavesTheRestOfTheHostTracked(): void
    {
        Manifest::start('k', $this->manifest->url, denylist: $this->host() . '/orders');
        self::assertSame(200, $this->guzzle()->get($this->upstream->url . '/ping')->getStatusCode());
        self::assertSame(400, $this->guzzle()->post($this->upstream->url . '/orders', ['json' => ['limit' => 500]])->getStatusCode());
        $this->tracking()->flush();
        self::assertSame([], $this->manifest->heals());
        self::assertSame([$this->upstream->url . '/ping'], array_column($this->manifest->tracked(), 'url'));
    }

    public function testAPatchNeverMovesTheRetryOntoADeniedRoute(): void
    {
        Manifest::start('k', $this->manifest->url, denylist: $this->host() . '/private');
        $this->manifest->setResult(['status' => 'patched', 'healAttemptId' => 'a1',
            'healedRequest' => ['url' => $this->upstream->url . '/private/orders', 'body' => ['limit' => 100]]]);
        self::assertSame(400, $this->guzzle()->post($this->upstream->url . '/orders', ['json' => ['limit' => 500]])->getStatusCode());
        // Replayed on the denied route, limit 100 would have answered 200.
        self::assertCount(1, $this->manifest->heals());
        self::assertSame('not_attempted', $this->manifest->outcomes()[0][1]['failure']['kind'] ?? null);
    }

    public function testSymfonyResponsesAreNotWrapped(): void
    {
        $client = new HealingHttpClient(new NativeHttpClient());
        self::assertSame(200, $client->request('GET', $this->upstream->url . '/ping')->getStatusCode());
        $response = $client->request('POST', $this->upstream->url . '/orders', ['json' => ['limit' => 500]]);
        self::assertSame(400, $response->getStatusCode());
        self::assertNotInstanceOf(LazyHealingResponse::class, $response);
        $this->assertNothingReachedManifest();
    }

    public function testCake(): void
    {
        listen();
        self::assertSame(200, (new CakeClient())->get($this->upstream->url . '/ping')->getStatusCode());
        self::assertSame(400, (new CakeClient())->post($this->upstream->url . '/orders', '{"limit":500}', ['type' => 'json'])->getStatusCode());
        $this->assertNothingReachedManifest();
    }

    public function testWordPress(): void
    {
        require_once __DIR__ . '/../Support/wordpress.php';
        \Mnfst\WordPress\listen();
        wp_remote_get($this->upstream->url . '/ping');
        $response = wp_remote_post($this->upstream->url . '/orders', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '{"limit":500}',
        ]);
        self::assertSame(400, $response['response']['code']);
        $this->assertNothingReachedManifest();
    }
}
