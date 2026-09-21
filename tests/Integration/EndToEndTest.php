<?php declare(strict_types=1);

namespace Mnfst\Tests\Integration;

use Cake\Http\Client as CakeClient;
use GuzzleHttp\Client as GuzzleClient;
use Mnfst\Config;
use Mnfst\HealApi;
use Mnfst\Handshake;
use Mnfst\Tests\Support\StubManifest;
use Mnfst\Tests\Support\StubUpstream;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient as SymfonyHttpClient;
use WpOrg\Requests\Requests;

use function Mnfst\manifest;

/**
 * End to end through the public entry point: manifest() installs the real
 * hooks, a genuine client call to a failing upstream is captured, healed by
 * the stub server, replayed, and the app receives the healed 200 with the
 * outcome reported. One test per supported client, each in its own process
 * because the hooks are process-global.
 *
 * The per-hook integration tests call Hooks\X::install() directly; these prove
 * the whole chain works the way a user wires it up (manifest(), then requests).
 */
#[RunTestsInSeparateProcesses]
final class EndToEndTest extends TestCase
{
    private StubManifest $manifest;

    private StubUpstream $upstream;

    protected function setUp(): void
    {
        // phpunit.xml sets MNFST_IN_TESTS=1, so manifest() installs its hooks here.
        $this->manifest = new StubManifest();
        $this->manifest->start();
        $this->upstream = new StubUpstream();
        $this->upstream->start();
        $this->manifest->setResult(['status' => 'patched', 'healAttemptId' => 'e2e', 'healedRequest' => ['body' => ['limit' => 100]]]);

        // The handshake and backoff markers live in the temp directory keyed by
        // server and key. Stub ports repeat across runs, so clear this config's
        // markers or a previous run's would suppress the announce.
        $config = Config::resolve('proj_key', $this->manifest->url);
        @unlink((new Handshake($config))->markerPath());
        @unlink((new HealApi($config))->backoffPath());

        manifest('proj_key', $this->manifest->url);
    }

    protected function tearDown(): void
    {
        $this->manifest->stop();
        $this->upstream->stop();
    }

    private function assertHealed(int $status, string $body): void
    {
        self::assertSame(200, $status, 'the app receives the healed response');
        self::assertStringContainsString('"limit":100', $body);
        self::assertCount(1, $this->manifest->heals(), 'exactly one capture');
        self::assertSame([['e2e', ['response' => ['statusCode' => 200]]]], $this->manifest->outcomes(), 'the success is reported');
        self::assertGreaterThanOrEqual(1, count($this->manifest->hellos()), 'the install announced itself');
    }

    public function testGuzzleEndToEnd(): void
    {
        $response = (new GuzzleClient(['http_errors' => false]))
            ->post($this->upstream->url.'/orders', ['json' => ['limit' => 500]]);

        $this->assertHealed($response->getStatusCode(), (string) $response->getBody());
    }

    public function testLaravelEndToEnd(): void
    {
        \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory());
        $response = \Illuminate\Support\Facades\Http::post($this->upstream->url.'/orders', ['limit' => 500]);
        $this->assertHealed($response->status(), $response->body());
    }

    public function testCakeEndToEnd(): void
    {
        $response = (new CakeClient())->post($this->upstream->url.'/orders', json_encode(['limit' => 500]), ['type' => 'json']);

        $this->assertHealed($response->getStatusCode(), $response->getStringBody());
    }

    public function testSymfonyEndToEnd(): void
    {
        $response = SymfonyHttpClient::create()->request('POST', $this->upstream->url.'/orders', ['json' => ['limit' => 500]]);

        $this->assertHealed($response->getStatusCode(), $response->getContent());
    }

    public function testWordPressEndToEnd(): void
    {
        $response = Requests::post($this->upstream->url.'/orders', ['Content-Type' => 'application/json'], json_encode(['limit' => 500]));

        $this->assertHealed($response->status_code, (string) $response->body);
    }

    public function testAnUnhealableFailureIsReturnedUnchangedEndToEnd(): void
    {
        $this->manifest->setResult(['status' => 'no_patch']);

        $response = (new GuzzleClient(['http_errors' => false]))
            ->post($this->upstream->url.'/orders', ['json' => ['limit' => 500]]);

        self::assertSame(400, $response->getStatusCode());
        self::assertCount(1, $this->manifest->heals());
        self::assertSame([], $this->manifest->outcomes(), 'no retry, nothing to report');
    }
}
