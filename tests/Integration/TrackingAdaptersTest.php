<?php declare(strict_types=1);

namespace Mnfst\Tests\Integration;

use Cake\Http\Client as CakeClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\HandlerStack;
use Mnfst\Config;
use Mnfst\HealApi;
use Mnfst\Manifest;
use Mnfst\Tests\Support\StubManifest;
use Mnfst\Tests\Support\StubUpstream;
use Mnfst\Tracking;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

use function Mnfst\Cake\listen;
use function Mnfst\Guzzle\middleware;

/**
 * Every adapter records the calls it does not heal, exactly once, and never
 * the calls it heals or the SDK's own.
 */
#[RunTestsInSeparateProcesses]
final class TrackingAdaptersTest extends TestCase
{
    private StubManifest $manifest;
    private StubUpstream $upstream;

    protected function setUp(): void
    {
        $this->manifest = new StubManifest();
        $this->manifest->start();
        $this->upstream = new StubUpstream();
        $this->upstream->start();
        Manifest::start('k', $this->manifest->url);
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

    private function tracking(): Tracking
    {
        $config = Config::resolve('k', $this->manifest->url);

        return new Tracking($config, new HealApi($config));
    }

    /** A client the way an app wires it: its own handler stack, plus the middleware. */
    private function client(array $config = []): GuzzleClient
    {
        $stack = $config['handler'] ?? HandlerStack::create();
        $stack->push(middleware());

        return new GuzzleClient(['handler' => $stack] + $config);
    }

    /** @return list<array{0: string, 1: int}> path and status of every tracked call */
    private function tracked(): array
    {
        $this->tracking()->flush();

        return array_map(
            fn (array $call): array => [substr($call['url'], strlen($this->upstream->url)), $call['statusCode']],
            $this->manifest->tracked(),
        );
    }

    public function testGuzzleTracksWhatItDoesNotHealOnceAndHealsTheRest(): void
    {
        $client = $this->client(['http_errors' => false]);
        $client->get($this->upstream->url . '/ping?token=secret');
        $client->get($this->upstream->url . '/unauthorized');
        $client->get($this->upstream->url . '/status/503');
        $client->post($this->upstream->url . '/orders', ['json' => ['limit' => 500]]);

        self::assertSame([['/ping', 200], ['/unauthorized', 401], ['/status/503', 503]], $this->tracked());
        self::assertCount(1, $this->manifest->heals());
    }

    public function testGuzzleTracksAServerErrorThatThrows(): void
    {
        try {
            $this->client()->get($this->upstream->url . '/status/502');
            self::fail('expected a ServerException');
        } catch (ServerException) {
        }

        self::assertSame([['/status/502', 502]], $this->tracked());
    }

    public function testTheSdkNeverTracksItsOwnCalls(): void
    {
        $this->client(['http_errors' => false])->post($this->upstream->url . '/orders', ['json' => ['limit' => 500]]);
        $this->tracked();

        // The handshake, the heal and the tracked-call send all went to Manifest; none was recorded.
        self::assertNotSame([], $this->manifest->requests());
        self::assertSame([], $this->manifest->tracked());
    }

    public function testAPausedProjectStillRecordsItsHealableFailures(): void
    {
        $this->manifest->setDisabled(true);
        $this->client(['http_errors' => false])->post($this->upstream->url . '/orders', ['json' => ['limit' => 500]]);
        $this->client(['http_errors' => false])->post($this->upstream->url . '/orders', ['json' => ['limit' => 500]]);
        $this->manifest->setDisabled(false);
        @unlink((new HealApi(Config::resolve('k', $this->manifest->url)))->backoffPath());

        // The first went to heal and was refused (project_disabled); the second found the pause and was recorded.
        self::assertSame([['/orders', 400]], $this->tracked());
    }

    public function testCakeTracksWhatItDoesNotHeal(): void
    {
        listen();
        (new CakeClient())->get($this->upstream->url . '/ping');
        (new CakeClient())->get($this->upstream->url . '/unauthorized');

        self::assertSame([['/ping', 200], ['/unauthorized', 401]], $this->tracked());
    }
}
