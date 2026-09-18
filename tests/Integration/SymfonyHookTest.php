<?php declare(strict_types=1);

namespace Mnfst\Tests\Integration;

use Mnfst\Config;
use Mnfst\HealApi;
use Mnfst\HealEvent;
use Mnfst\Hooks\Symfony;
use Mnfst\Tests\Support\StubManifest;
use Mnfst\Tests\Support\StubUpstream;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\HttpClient;

/** Hooks are process-global, so each test gets a process. */
#[RunTestsInSeparateProcesses]
final class SymfonyHookTest extends TestCase
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
        Symfony::install($config, new HealApi($config));
    }

    protected function tearDown(): void
    {
        $this->manifest->stop();
        $this->upstream->stop();
    }

    private function healTo(array $body): void
    {
        $this->manifest->setResult(['status' => 'patched', 'healAttemptId' => 'a1', 'healedRequest' => ['body' => $body]]);
    }

    public function testHealsAFailingRequest(): void
    {
        $this->healTo(['limit' => 100]);

        $response = HttpClient::create()->request('POST', $this->upstream->url.'/orders', ['json' => ['limit' => 500]]);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('"limit":100', $response->getContent());
        self::assertSame([['a1', ['response' => ['statusCode' => 200]]]], $this->manifest->outcomes());
        self::assertSame('patched', $this->events[0]->healStatus);
        self::assertSame(200, $this->events[0]->replayStatusCode);
    }

    public function testASuccessfulResponsePassesThroughUntouched(): void
    {
        $response = HttpClient::create()->request('POST', $this->upstream->url.'/orders', ['json' => ['limit' => 5]]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->manifest->heals(), 'a 200 must never reach /v1/heal');
    }

    public function testNoPatchReturnsTheOriginalResponse(): void
    {
        $this->manifest->setResult(['status' => 'no_patch']);

        $response = HttpClient::create()->request('POST', $this->upstream->url.'/orders', ['json' => ['limit' => 500]]);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('too big', $response->getContent(false));
        self::assertCount(1, $this->manifest->heals());
    }

    public function testAForbiddenStatusIsNeverCaptured(): void
    {
        HttpClient::create()->request('GET', $this->upstream->url.'/unauthorized')->getStatusCode();

        self::assertSame([], $this->manifest->heals());
    }

    public function testTheNormalizedRequestIsReported(): void
    {
        $this->manifest->setResult(['status' => 'no_patch']);

        HttpClient::create(['headers' => ['X-Default' => 'd']])
            ->request('POST', $this->upstream->url.'/orders?existing=1', ['query' => ['page' => '2'], 'json' => ['limit' => 500], 'auth_bearer' => 'sk_live'])
            ->getStatusCode();

        $sent = $this->manifest->heals()[0]['request'];
        self::assertSame('POST', $sent['method']);
        self::assertStringContainsString('existing=1&page=2', $sent['url'], 'query option folded into the URL');
        self::assertSame('REDACTED', $sent['headers']['authorization'], 'auth_bearer masked');
        self::assertSame('d', $sent['headers']['x-default']);
        self::assertSame(['limit' => 500], $sent['body']);
    }

    public function testTheCallerCanStillReadTheBodyAfterACapture(): void
    {
        $this->manifest->setResult(['status' => 'no_patch']);

        $response = HttpClient::create()->request('POST', $this->upstream->url.'/orders', ['json' => ['limit' => 500]]);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('too big', $response->getContent(false));
        self::assertStringContainsString('too big', $response->getContent(false), 'buffered body is re-readable');
    }

    public function testAThrowingReadStillThrowsWhenTheRetryFails(): void
    {
        $this->healTo(['limit' => 400]);   // the heal changes nothing that matters: the retry fails again

        $this->expectException(ClientException::class);
        HttpClient::create()->request('POST', $this->upstream->url.'/orders', ['json' => ['limit' => 500]])->getContent();
    }

    public function testConcurrentRequestsStreamWithoutError(): void
    {
        $this->healTo(['limit' => 100]);
        $client = HttpClient::create();
        $responses = [
            $client->request('GET', $this->upstream->url.'/ping'),
            $client->request('GET', $this->upstream->url.'/ping'),
        ];
        $completed = 0;
        foreach ($client->stream($responses) as $chunk) {
            if ($chunk->isLast()) {
                $completed++;
            }
        }
        self::assertSame(2, $completed, 'wrapped responses still stream through the transport');
    }

    public function testTheRetryIsNotItselfCaptured(): void
    {
        $this->healTo(['limit' => 100]);

        HttpClient::create()->request('POST', $this->upstream->url.'/orders', ['json' => ['limit' => 500]])->getStatusCode();

        self::assertCount(1, $this->manifest->heals(), 'the retry must not be captured as a new failure');
    }
}
