<?php declare(strict_types=1);

namespace Mnfst\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Mnfst\Manifest;
use Mnfst\Tests\Support\StubManifest;
use Mnfst\Tests\Support\StubUpstream;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

use function Mnfst\Guzzle\middleware;

#[RunTestsInSeparateProcesses]
final class ManifestTest extends TestCase
{
    private StubManifest $manifest;
    private StubUpstream $upstream;

    protected function setUp(): void
    {
        $this->manifest = new StubManifest();
        $this->manifest->start();
        $this->upstream = new StubUpstream();
        $this->upstream->start();
    }

    protected function tearDown(): void
    {
        $this->manifest->stop();
        $this->upstream->stop();
    }

    /** A client the way an app wires it: its own handler stack, plus the middleware. */
    private function client(array $config = []): Client
    {
        $stack = $config['handler'] ?? HandlerStack::create();
        $stack->push(middleware());

        return new Client(['handler' => $stack] + $config);
    }

    public function testWithoutAKeyTheSdkIsInert(): void
    {
        Manifest::start(null, $this->manifest->url);
        $response = ($this->client(['http_errors' => false]))
            ->post($this->upstream->url . '/orders', ['json' => ['limit' => 500]]);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->manifest->heals(), 'no key, no capture');
        self::assertSame([], $this->manifest->hellos(), 'no key, no handshake');
    }
}
