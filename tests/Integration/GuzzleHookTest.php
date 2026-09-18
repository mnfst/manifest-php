<?php declare(strict_types=1);

namespace Mnfst\Tests\Integration;

use GuzzleHttp\Client;
use Mnfst\Config;
use Mnfst\HealApi;
use Mnfst\Hooks\Guzzle;
use Mnfst\Tests\Support\StubManifest;
use Mnfst\Tests\Support\StubUpstream;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/** Hooks are process-global and cannot be removed, so each test gets a process. */
#[RunTestsInSeparateProcesses]
final class GuzzleHookTest extends TestCase
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
        Guzzle::install($config, new HealApi($config));
    }

    protected function tearDown(): void
    {
        $this->manifest->stop();
        $this->upstream->stop();
    }

    private function healTo(array $body): void
    {
        $this->manifest->setResult([
            'status' => 'patched',
            'healAttemptId' => 'a1',
            'healedRequest' => ['body' => $body],
        ]);
    }

    public function testHealsAFailingRequest(): void
    {
        $this->healTo(['limit' => 100]);
        $response = (new Client(['http_errors' => false]))
            ->post($this->upstream->url . '/orders', ['json' => ['limit' => 500]]);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('"limit":100', (string) $response->getBody());
    }

    public function testHealsWhenHttpErrorsThrows(): void
    {
        $this->healTo(['limit' => 100]);
        $response = (new Client())->post($this->upstream->url . '/orders', ['json' => ['limit' => 500]]);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testASuccessfulResponsePassesThroughUntouched(): void
    {
        $response = (new Client(['http_errors' => false]))
            ->post($this->upstream->url . '/orders', ['json' => ['limit' => 5]]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->manifest->heals(), 'a 200 must never reach /v1/heal');
    }

    public function testAForbiddenStatusIsNeverCaptured(): void
    {
        (new Client(['http_errors' => false]))->get($this->upstream->url . '/unauthorized');
        self::assertSame([], $this->manifest->heals());
    }

    public function testNoPatchReturnsTheOriginalResponse(): void
    {
        $this->manifest->setResult(['status' => 'no_patch']);
        $response = (new Client(['http_errors' => false]))
            ->post($this->upstream->url . '/orders', ['json' => ['limit' => 500]]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testTheOutcomeIsReported(): void
    {
        $this->healTo(['limit' => 100]);
        (new Client(['http_errors' => false]))->post($this->upstream->url . '/orders', ['json' => ['limit' => 500]]);

        self::assertSame([['a1', ['response' => ['statusCode' => 200]]]], $this->manifest->outcomes());
    }

    public function testTheSdkOwnCallsAreNotCaptured(): void
    {
        $this->healTo(['limit' => 100]);
        (new Client(['http_errors' => false]))->post($this->upstream->url . '/orders', ['json' => ['limit' => 500]]);

        self::assertCount(1, $this->manifest->heals(), 'the retry must not be captured as a new failure');
    }

    public function testConcurrentAsyncRequestsAreBothHealed(): void
    {
        $this->healTo(['limit' => 100]);
        $client = new Client(['http_errors' => false]);
        $promises = [
            $client->postAsync($this->upstream->url . '/orders', ['json' => ['limit' => 700]]),
            $client->postAsync($this->upstream->url . '/orders', ['json' => ['limit' => 800]]),
        ];
        foreach (\GuzzleHttp\Promise\Utils::unwrap($promises) as $response) {
            self::assertSame(200, $response->getStatusCode());
        }
    }
}
