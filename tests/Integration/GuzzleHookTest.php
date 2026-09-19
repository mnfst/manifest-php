<?php declare(strict_types=1);

namespace Mnfst\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
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

    public function testAHealedQueryIsAppliedAndAGetRetriesBodyless(): void
    {
        $this->manifest->setResult([
            'status' => 'patched',
            'healAttemptId' => 'a1',
            'healedRequest' => ['url' => $this->upstream->url . '/orders?limit=100', 'body' => null],
        ]);
        $response = (new Client(['http_errors' => false]))->get($this->upstream->url . '/orders?limit=500');

        self::assertSame(200, $response->getStatusCode());
        $echo = json_decode((string) $response->getBody(), true);
        self::assertSame(0, $echo['rawLength'], 'a GET must never grow a body');
        self::assertSame([['a1', ['response' => ['statusCode' => 200]]]], $this->manifest->outcomes());
    }

    public function testAHealedHeaderIsApplied(): void
    {
        $this->manifest->setResult([
            'status' => 'patched',
            'healAttemptId' => 'a1',
            'healedRequest' => ['headers' => ['x-limit' => '100'], 'body' => []],
        ]);
        $response = (new Client(['http_errors' => false]))
            ->post($this->upstream->url . '/orders', ['json' => ['limit' => 500]]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('100', json_decode((string) $response->getBody(), true)['headers']['x-limit']);
    }

    public function testTheRetryKeepsTheCallersHandlerStack(): void
    {
        $this->healTo(['limit' => 100]);
        $mock = new MockHandler([
            new Response(400, ['Content-Type' => 'application/json'], '{"error":"too big"}'),
            new Response(200, [], '{"faked":true}'),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock), 'http_errors' => false]);

        $response = $client->post('https://api.example/orders', ['json' => ['limit' => 500]]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"faked":true}', (string) $response->getBody(), 'the retry must go through the same handler');
        self::assertSame(0, $mock->count());
        self::assertSame('{"limit":100}', (string) $mock->getLastRequest()->getBody());
    }

    public function testAFailedRetryStillThrowsUnderHttpErrors(): void
    {
        $this->healTo(['limit' => 300]);   // still over the limit: the retry fails too
        try {
            (new Client())->post($this->upstream->url . '/orders', ['json' => ['limit' => 500]]);
            self::fail('a 4xx retry must reject like the original did');
        } catch (ClientException $e) {
            self::assertSame(400, $e->getResponse()->getStatusCode());
            self::assertSame('{"limit":300}', (string) $e->getRequest()->getBody(), 'the exception names the retried request');
        }
        [[, $sent]] = $this->manifest->outcomes();
        self::assertSame(400, $sent['response']['statusCode']);
        self::assertSame('limit must be at most 100, too big', $sent['response']['body']['error']);
    }

    public function testATransportFailureOnTheRetryIsReported(): void
    {
        $this->healTo(['limit' => 100]);
        $mock = new MockHandler([
            new Response(400, ['Content-Type' => 'application/json'], '{"error":"too big"}'),
            new ConnectException('connection reset', new Request('POST', 'https://api.example/orders')),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock), 'http_errors' => false]);

        $response = $client->post('https://api.example/orders', ['json' => ['limit' => 500]]);

        self::assertSame(400, $response->getStatusCode(), 'the caller gets the original response');
        [[, $sent]] = $this->manifest->outcomes();
        self::assertSame('transport_error', $sent['failure']['kind']);
        self::assertStringContainsString('connection reset', $sent['failure']['message']);
    }

    public function testAnUnreplayableAnswerClosesTheAttempt(): void
    {
        $this->manifest->setResult([
            'status' => 'patched',
            'healAttemptId' => 'a1',
            'healedRequest' => ['url' => 'https://elsewhere.test/orders'],
        ]);
        $response = (new Client(['http_errors' => false]))
            ->post($this->upstream->url . '/orders', ['json' => ['limit' => 500]]);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([['a1', ['failure' => ['kind' => 'not_attempted', 'message' => 'replay_not_attempted']]]], $this->manifest->outcomes());
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
