<?php declare(strict_types=1);

namespace Mnfst\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Mnfst\Config;
use Mnfst\HealApi;
use Mnfst\HealEvent;
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

    /** @var list<HealEvent> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->manifest = new StubManifest();
        $this->manifest->start();
        $this->upstream = new StubUpstream();
        $this->upstream->start();

        $config = Config::resolve('k', $this->manifest->url, function (HealEvent $event): void {
            $this->events[] = $event;
        });
        Guzzle::install($config, new HealApi($config));
    }

    protected function tearDown(): void
    {
        $this->manifest->stop();
        $this->upstream->stop();
    }

    private function healTo(array $body): void
    {
        $this->healRequestTo(['body' => $body]);
    }

    /** @param array<string, mixed> $healedRequest */
    private function healRequestTo(array $healedRequest): void
    {
        $this->manifest->setResult(['status' => 'patched', 'healAttemptId' => 'a1', 'healedRequest' => $healedRequest]);
    }

    /** @return array<string, mixed> what the stub upstream saw on the request it answered */
    private function search(Client $client, string $query, array $options = []): array
    {
        $response = $client->get($this->upstream->url . '/search?' . $query, $options);
        self::assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true);
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

    public function testAFailedRetryStillThrowsWhenHttpErrorsIsOn(): void
    {
        $this->healTo(['limit' => 400]);   // the heal changes nothing that matters: the retry fails again

        try {
            (new Client())->post($this->upstream->url . '/orders', ['json' => ['limit' => 500]]);
            self::fail('a 400 must throw for a client with http_errors on, retry or not');
        } catch (ClientException $e) {
            self::assertSame(400, $e->getResponse()->getStatusCode());
            self::assertStringContainsString('too big', $e->getResponse()->getBody()->getContents());
        }
        self::assertSame(400, $this->manifest->outcomes()[0][1]['response']['statusCode']);
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
        self::assertStringContainsString('too big', $response->getBody()->getContents(), 'the SDK read the body; the caller must still be able to');
    }

    public function testAStreamedErrorBodyStaysReadable(): void
    {
        $this->manifest->setResult(['status' => 'no_patch']);
        $response = (new Client(['http_errors' => false, 'stream' => true]))
            ->post($this->upstream->url . '/orders', ['json' => ['limit' => 500]]);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('too big', $response->getBody()->getContents());
        self::assertCount(1, $this->manifest->heals());
    }

    public function testAStreamedFailureStillThrowsWithTheBodyWhenHttpErrorsIsOn(): void
    {
        $this->manifest->setResult(['status' => 'no_patch']);
        try {
            (new Client(['stream' => true]))->post($this->upstream->url . '/orders', ['json' => ['limit' => 500]]);
            self::fail('a 400 must throw');
        } catch (ClientException $e) {
            self::assertStringContainsString('too big', $e->getResponse()->getBody()->getContents());
        }
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

    /** Issue #5: a URL heal on a GET must replay the GET, with its headers and without a body. */
    public function testAppliesAHealedUrlToAGetAndKeepsTheOriginalRequest(): void
    {
        $this->healRequestTo(['url' => $this->upstream->url . '/search?query=Batman', 'body' => null]);
        $client = new Client(['http_errors' => false, 'headers' => ['X-Client-Default' => 'yes']]);

        $echo = $this->search($client, 'query=Batman&page=1&page=2', ['headers' => ['Authorization' => 'Bearer secret-token']]);

        self::assertSame('GET', $echo['method']);
        self::assertSame('query=Batman', $echo['query']);
        self::assertSame('', $echo['body'], 'a GET retries without a body, never the JSON literal null');
        self::assertSame('Bearer secret-token', $echo['headers']['authorization']);
        self::assertSame('yes', $echo['headers']['x-client-default'], 'the retry goes through the same client');
        self::assertSame([['a1', ['response' => ['statusCode' => 200]]]], $this->manifest->outcomes());
    }

    public function testTheHealedBodyWinsOverTheOriginalJsonOption(): void
    {
        $this->healTo(['limit' => 100]);
        $response = (new Client(['http_errors' => false]))
            ->post($this->upstream->url . '/orders', ['json' => ['limit' => 500], 'headers' => ['X-Trace' => 't1']]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['limit' => 100], json_decode((string) $response->getBody(), true)['got']);
    }

    public function testHealedHeadersAreSetAndRemoved(): void
    {
        $this->healRequestTo([
            'url' => $this->upstream->url . '/search?query=Batman',
            'headers' => ['X-Api-Version' => '2022-11-28', 'X-Legacy' => null],
        ]);

        $echo = $this->search(new Client(['http_errors' => false]), 'query=Batman&page=1&page=2', ['headers' => ['X-Legacy' => '1']]);

        self::assertSame('2022-11-28', $echo['headers']['x-api-version']);
        self::assertArrayNotHasKey('x-legacy', $echo['headers']);
    }

    public function testMaskedQueryValuesAreRestoredOnTheRetry(): void
    {
        $this->healRequestTo(['url' => $this->upstream->url . '/search?api_key=REDACTED&page=1']);

        $echo = $this->search(new Client(['http_errors' => false]), 'api_key=sk_live_1&page=1&page=2');

        self::assertSame('api_key=sk_live_1&page=1', $echo['query']);
        self::assertStringContainsString('api_key=REDACTED&page=1&page=2', $this->manifest->heals()[0]['request']['url']);
    }

    public function testAHealedUrlOnAnotherOriginIsNotReplayed(): void
    {
        $this->healRequestTo(['url' => 'https://evil.test/search?query=Batman']);

        $response = (new Client(['http_errors' => false]))->get($this->upstream->url . '/search?query=Batman&page=1&page=2');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('not_attempted', $this->manifest->outcomes()[0][1]['failure']['kind']);
    }

    public function testOnHealReceivesTheOutcome(): void
    {
        $this->healRequestTo(['url' => $this->upstream->url . '/search?query=Batman']);
        $this->search(new Client(['http_errors' => false]), 'query=Batman&page=1&page=2');

        self::assertCount(1, $this->events);
        self::assertSame('patched', $this->events[0]->healStatus);
        self::assertSame(400, $this->events[0]->statusCode);
        self::assertSame(200, $this->events[0]->replayStatusCode);
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
