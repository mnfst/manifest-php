<?php declare(strict_types=1);

namespace Mnfst\Tests\Integration;

use Mnfst\Config;
use Mnfst\HealApi;
use Mnfst\Hooks\Curl;
use Mnfst\Tests\Support\StubManifest;
use Mnfst\Tests\Support\StubUpstream;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
final class CurlHookTest extends TestCase
{
    private StubManifest $manifest;
    private StubUpstream $upstream;

    protected function setUp(): void
    {
        $this->manifest = new StubManifest();
        $this->manifest->start();
        $this->upstream = new StubUpstream();
        $this->upstream->start();

        $config = Config::resolve('k', $this->manifest->url);
        Curl::install($config, new HealApi($config));
    }

    protected function tearDown(): void
    {
        $this->manifest->stop();
        $this->upstream->stop();
    }

    /** @return array{0: int, 1: string} */
    private function post(string $path, array $body): array
    {
        $ch = curl_init($this->upstream->url . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return [$status, $raw];
    }

    public function testAFailureIsCaptured(): void
    {
        $this->post('/orders', ['limit' => 500]);

        $heals = $this->manifest->heals();
        self::assertCount(1, $heals);
        self::assertSame(400, $heals[0]['response']['statusCode']);
        self::assertStringContainsString('/orders', $heals[0]['request']['url']);
    }

    public function testTheRequestAndResponseBodiesTravel(): void
    {
        $this->post('/orders', ['limit' => 500]);

        [$heal] = $this->manifest->heals();
        self::assertSame(['limit' => 500], $heal['request']['body']);
        self::assertStringContainsString('too big', $heal['response']['body']['error']);
    }

    public function testTheApplicationStillReceivesTheOriginalError(): void
    {
        [$status, $raw] = $this->post('/orders', ['limit' => 500]);

        self::assertSame(400, $status, 'raw curl can never be healed');
        self::assertStringContainsString('too big', $raw);
    }

    public function testNoHealAttemptIsOpened(): void
    {
        $this->manifest->setResult([
            'status' => 'patched',
            'healAttemptId' => 'a1',
            'healedRequest' => ['body' => ['limit' => 100]],
        ]);
        $this->post('/orders', ['limit' => 500]);

        self::assertSame([], $this->manifest->outcomes(), 'nothing was replayed, so nothing is adjudicated');
    }

    public function testASuccessIsNotCaptured(): void
    {
        $this->post('/orders', ['limit' => 5]);
        self::assertSame([], $this->manifest->heals());
    }

    public function testTheSdkOwnCallsAreNotCaptured(): void
    {
        $this->post('/orders', ['limit' => 500]);
        // the SDK's own POST /v1/heal is itself a curl_exec; it must not recurse
        self::assertCount(1, $this->manifest->heals());
    }

    public function testGuzzleTrafficIsLeftToTheGuzzleHook(): void
    {
        // Guzzle runs on curl underneath. With both hooks installed the failure
        // must be reported once, by the hook that can actually heal it.
        \Mnfst\Hooks\Guzzle::install(
            Config::resolve('k', $this->manifest->url),
            new HealApi(Config::resolve('k', $this->manifest->url)),
        );

        (new \GuzzleHttp\Client(['http_errors' => false]))
            ->post($this->upstream->url . '/orders', ['json' => ['limit' => 500]]);

        self::assertCount(1, $this->manifest->heals(), 'exactly one report, not one per layer');
    }
}
