<?php declare(strict_types=1);

namespace Mnfst\Tests\Integration;

use Cake\Http\Client;
use Mnfst\Config;
use Mnfst\HealApi;
use Mnfst\HealEvent;
use Mnfst\Hooks\Cake;
use Mnfst\Tests\Support\StubManifest;
use Mnfst\Tests\Support\StubUpstream;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
final class CakeHookTest extends TestCase
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
        $this->manifest->setResult([
            'status' => 'patched',
            'healAttemptId' => 'a1',
            'healedRequest' => ['body' => ['limit' => 100]],
        ]);

        $config = Config::resolve('k', $this->manifest->url, function (HealEvent $event): void {
            $this->events[] = $event;
        });
        Cake::install($config, new HealApi($config));
    }

    /** @param array<string, mixed> $healedRequest */
    private function healTo(array $healedRequest): void
    {
        $this->manifest->setResult(['status' => 'patched', 'healAttemptId' => 'a1', 'healedRequest' => $healedRequest]);
    }

    /** @return array<string, mixed> what the stub upstream saw on the request it answered */
    private function search(string $query, array $headers = ['Authorization' => 'Bearer secret-token']): array
    {
        $response = (new Client())->get($this->upstream->url . '/search?' . $query, [], ['headers' => $headers]);
        self::assertSame(200, $response->getStatusCode());

        return $response->getJson();
    }

    protected function tearDown(): void
    {
        $this->manifest->stop();
        $this->upstream->stop();
    }

    public function testHealsAFailingRequest(): void
    {
        $response = (new Client())->post(
            $this->upstream->url . '/orders',
            json_encode(['limit' => 500]),
            ['type' => 'json'],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->isOk());
        self::assertStringContainsString('"limit":100', $response->getStringBody());
    }

    public function testASuccessfulResponsePassesThroughUntouched(): void
    {
        $response = (new Client())->post(
            $this->upstream->url . '/orders',
            json_encode(['limit' => 5]),
            ['type' => 'json'],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->manifest->heals());
    }

    public function testAGetWithNoBodyIsUnaffected(): void
    {
        self::assertSame(200, (new Client())->get($this->upstream->url . '/ping')->getStatusCode());
    }

    public function testTheRetryIsNotItselfCaptured(): void
    {
        (new Client())->post($this->upstream->url . '/orders', json_encode(['limit' => 500]), ['type' => 'json']);
        self::assertCount(1, $this->manifest->heals());
    }

    /** The bug of issue #5: a URL heal on a GET was replayed as a bare POST to the original URL. */
    public function testAppliesAHealedUrlToAGetAndKeepsTheOriginalRequest(): void
    {
        $this->healTo(['url' => $this->upstream->url . '/search?query=Batman', 'body' => null]);

        $echo = $this->search('query=Batman&page=1&page=2', ['Authorization' => 'Bearer secret-token', 'Accept' => 'application/json']);

        self::assertSame('GET', $echo['method']);
        self::assertSame('query=Batman', $echo['query']);
        self::assertSame('', $echo['body'], 'a GET retries without a body, never the JSON literal null');
        self::assertSame('Bearer secret-token', $echo['headers']['authorization']);
        self::assertSame('application/json', $echo['headers']['accept']);
        self::assertSame([['a1', ['response' => ['statusCode' => 200]]]], $this->manifest->outcomes());
    }

    public function testTheServerSeesTheQueryExactlyAsSent(): void
    {
        $this->manifest->setResult(['status' => 'no_patch']);
        (new Client())->get($this->upstream->url . '/search?query=Batman&page=1&page=2&api_key=sk_1');

        $sent = $this->manifest->heals()[0]['request'];
        self::assertSame('GET', $sent['method']);
        self::assertStringEndsWith('/search?query=Batman&page=1&page=2&api_key=REDACTED', $sent['url']);
    }

    public function testHealedHeadersAreSetAndRemoved(): void
    {
        $this->healTo([
            'url' => $this->upstream->url . '/search?query=Batman',
            'headers' => ['X-Api-Version' => '2022-11-28', 'Accept' => null],
        ]);

        $echo = $this->search('query=Batman&page=1&page=2', ['Authorization' => 'Bearer t', 'Accept' => 'application/xml']);

        self::assertSame('2022-11-28', $echo['headers']['x-api-version']);
        // Removed from the request; what remains is libcurl's own default for a request without one.
        self::assertSame('*/*', $echo['headers']['accept'] ?? '*/*');
        self::assertSame('Bearer t', $echo['headers']['authorization']);
    }

    public function testMaskedQueryValuesAreRestoredOnTheRetry(): void
    {
        $this->healTo(['url' => $this->upstream->url . '/search?api_key=REDACTED&page=1']);

        $echo = $this->search('api_key=sk_live_1&page=1&page=2');

        self::assertSame('api_key=sk_live_1&page=1', $echo['query']);
        self::assertStringContainsString('api_key=REDACTED&page=1&page=2', $this->manifest->heals()[0]['request']['url']);
    }

    public function testAQueryCredentialTheHealedUrlLeftOutIsSentOnTheRetry(): void
    {
        $this->healTo(['url' => $this->upstream->url . '/search?query=Batman']);

        $echo = $this->search('query=Batman&page=1&page=2&api_key=sk_live_1');

        self::assertSame('query=Batman&api_key=sk_live_1', $echo['query']);
    }

    public function testAHealedUrlOnAnotherOriginIsNotReplayed(): void
    {
        $this->healTo(['url' => 'https://evil.test/search?query=Batman']);

        $response = (new Client())->get($this->upstream->url . '/search?query=Batman&page=1&page=2');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([['a1', ['failure' => ['kind' => 'not_attempted', 'message' => 'replay_not_attempted']]]], $this->manifest->outcomes());
    }

    public function testABodilessPostIsNotReplayedOnAUrlOnlyHeal(): void
    {
        $this->healTo(['url' => $this->upstream->url . '/search?query=Batman']);

        $response = (new Client())->post($this->upstream->url . '/search?query=Batman&page=1&page=2');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('not_attempted', $this->manifest->outcomes()[0][1]['failure']['kind']);
    }

    public function testTheResponseTimeIsMeasured(): void
    {
        $this->manifest->setResult(['status' => 'no_patch']);
        (new Client())->get($this->upstream->url . '/slow');

        self::assertGreaterThanOrEqual(25, $this->manifest->heals()[0]['responseTimeMs']);
    }

    public function testOnHealReceivesTheOutcome(): void
    {
        $this->healTo(['url' => $this->upstream->url . '/search?query=Batman']);
        $this->search('query=Batman&page=1&page=2');

        self::assertCount(1, $this->events);
        $event = $this->events[0];
        self::assertSame('patched', $event->healStatus);
        self::assertSame(400, $event->statusCode);
        self::assertSame(200, $event->replayStatusCode);
        self::assertStringEndsWith('/search?query=Batman&page=1&page=2', $event->url);
        self::assertGreaterThanOrEqual(0, $event->healMs);

        $this->manifest->setResult(['status' => 'no_patch']);
        (new Client())->get($this->upstream->url . '/search?query=Batman&page=1&page=2');
        self::assertSame('no_patch', $this->events[1]->healStatus);
        self::assertNull($this->events[1]->replayStatusCode);
    }
}
