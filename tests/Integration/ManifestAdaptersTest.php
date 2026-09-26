<?php declare(strict_types=1);

namespace Mnfst\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Mnfst\Config;
use Mnfst\Handshake;
use Mnfst\HealApi;
use Mnfst\Manifest;
use Mnfst\Tests\Support\StubManifest;
use Mnfst\Tests\Support\StubUpstream;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

use function Mnfst\Guzzle\middleware;
use function Mnfst\manifest;

/** Manifest's state is process-global, so each test gets a process. */
#[RunTestsInSeparateProcesses]
final class ManifestAdaptersTest extends TestCase
{
    private StubManifest $manifest;
    private StubUpstream $upstream;

    protected function setUp(): void
    {
        $this->manifest = new StubManifest();
        $this->manifest->start();
        $this->upstream = new StubUpstream();
        $this->upstream->start();
        $this->manifest->setResult(['status' => 'patched', 'healAttemptId' => 'a1', 'healedRequest' => ['body' => ['limit' => 100]]]);
        // Stub ports repeat across runs: clear this config's handshake and backoff markers.
        $config = Config::resolve('k', $this->manifest->url);
        @unlink((new Handshake($config))->markerPath());
        @unlink((new HealApi($config))->backoffPath());
    }

    protected function tearDown(): void
    {
        $this->manifest->stop();
        $this->upstream->stop();
    }

    private function client(): Client
    {
        $stack = HandlerStack::create();
        $stack->push(middleware());

        return new Client(['handler' => $stack, 'http_errors' => false]);
    }

    public function testAMiddlewareBuiltBeforeStartHealsOnceStarted(): void
    {
        $client = $this->client();
        self::assertSame(400, $client->post($this->upstream->url . '/orders', ['json' => ['limit' => 500]])->getStatusCode());
        self::assertSame([], $this->manifest->heals(), 'not started yet: the call passes through');

        manifest('k', $this->manifest->url);

        self::assertSame(200, $client->post($this->upstream->url . '/orders', ['json' => ['limit' => 500]])->getStatusCode());
        self::assertCount(1, $this->manifest->heals());
    }

    public function testNoHandshakeUntilAnAdapterIsRegistered(): void
    {
        manifest('k', $this->manifest->url);
        self::assertSame([], $this->manifest->hellos(), 'started, but nothing can report yet');

        middleware();

        self::assertCount(1, $this->manifest->hellos());
        self::assertSame(['guzzle'], Manifest::adapters());
    }

    public function testTheHandshakeIsSentOnceWhicheverComesLast(): void
    {
        middleware();
        manifest('k', $this->manifest->url);
        middleware();
        manifest('k', $this->manifest->url);

        self::assertCount(1, $this->manifest->hellos());
    }
}
