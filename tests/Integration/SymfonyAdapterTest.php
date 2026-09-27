<?php declare(strict_types=1);

namespace Mnfst\Tests\Integration;

use Mnfst\HealEvent;
use Mnfst\Symfony\HealingHttpClient;
use Mnfst\Tests\Support\StubManifest;
use Mnfst\Tests\Support\StubUpstream;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function Mnfst\manifest;

/** Manifest's state is process-global, so each test gets a process. */
#[RunTestsInSeparateProcesses]
final class SymfonyAdapterTest extends TestCase
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

        manifest('k', $this->manifest->url, function (HealEvent $event): void {
            $this->events[] = $event;
        });
    }

    /** The transport, decorated the way ManifestBundle decorates the http_client service. */
    private function client(array $defaults = []): HttpClientInterface
    {
        return new HealingHttpClient(HttpClient::create($defaults));
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

        $response = $this->client()->request('POST', $this->upstream->url.'/orders', ['json' => ['limit' => 500]]);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('"limit":100', $response->getContent());
        self::assertSame([['a1', ['response' => ['statusCode' => 200]]]], $this->manifest->outcomes());
        self::assertSame('patched', $this->events[0]->healStatus);
        self::assertSame(200, $this->events[0]->replayStatusCode);
    }

    public function testTheNativeTransportHealsWithTheSameContract(): void
    {
        $this->healTo(['limit' => 100]);
        $response = (new HealingHttpClient(new \Symfony\Component\HttpClient\NativeHttpClient()))
            ->request('POST', $this->upstream->url.'/orders', ['json' => ['limit' => 500]]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(100, $response->toArray()['got']['limit']);
        self::assertCount(1, $this->manifest->heals());
    }

    public function testASuccessfulResponsePassesThroughUntouched(): void
    {
        $response = $this->client()->request('POST', $this->upstream->url.'/orders', ['json' => ['limit' => 5]]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->manifest->heals(), 'a 200 must never reach /v1/heal');
    }

    public function testNoPatchReturnsTheOriginalResponse(): void
    {
        $this->manifest->setResult(['status' => 'no_patch']);

        $response = $this->client()->request('POST', $this->upstream->url.'/orders', ['json' => ['limit' => 500]]);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('too big', $response->getContent(false));
        self::assertCount(1, $this->manifest->heals());
    }

    public function testAForbiddenStatusIsNeverCaptured(): void
    {
        $this->client()->request('GET', $this->upstream->url.'/unauthorized')->getStatusCode();

        self::assertSame([], $this->manifest->heals());
    }

    public function testTheNormalizedRequestIsReported(): void
    {
        $this->manifest->setResult(['status' => 'no_patch']);

        $this->client()
            ->request('POST', $this->upstream->url.'/orders?existing=1', ['query' => ['page' => '2'], 'json' => ['limit' => 500], 'auth_bearer' => 'sk_live', 'headers' => ['X-Default' => 'd']])
            ->getStatusCode();

        $sent = $this->manifest->heals()[0]['request'];
        self::assertSame('POST', $sent['method']);
        self::assertStringContainsString('existing=1&page=2', $sent['url'], 'query option folded into the URL');
        self::assertSame('Bearer REDACTED', $sent['headers']['authorization'], 'auth_bearer masked, scheme kept');
        self::assertSame('d', $sent['headers']['x-default']);
        self::assertSame(['limit' => 500], $sent['body']);
    }

    public function testTheCallerCanStillReadTheBodyAfterACapture(): void
    {
        $this->manifest->setResult(['status' => 'no_patch']);

        $response = $this->client()->request('POST', $this->upstream->url.'/orders', ['json' => ['limit' => 500]]);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('too big', $response->getContent(false));
        self::assertStringContainsString('too big', $response->getContent(false), 'buffered body is re-readable');
    }

    public function testAThrowingReadStillThrowsWhenTheRetryFails(): void
    {
        $this->healTo(['limit' => 400]);   // the heal changes nothing that matters: the retry fails again

        $this->expectException(ClientException::class);
        $this->client()->request('POST', $this->upstream->url.'/orders', ['json' => ['limit' => 500]])->getContent();
    }

    public function testRetryPreservesPerRequestOptionsAndRemovesHeaders(): void
    {
        $this->manifest->setResult(['status' => 'patched', 'healAttemptId' => 'a1', 'healedRequest' => [
            'body' => ['limit' => 100], 'headers' => ['X-Old' => null, 'Authorization' => null],
        ]]);
        $client = $this->client();
        $response = $client->request('POST', $this->upstream->url.'/orders', [
            'json' => ['limit' => 500], 'user_data' => 'caller-context', 'timeout' => 2,
            'headers' => ['X-Old' => 'remove'], 'auth_bearer' => 'local-secret',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('caller-context', $response->getInfo('user_data'));
        $echo = $response->toArray();
        self::assertArrayNotHasKey('x-old', $echo['headers']);
        self::assertArrayNotHasKey('authorization', $echo['headers']);
    }

    public function testUnbufferedFailuresRemainReadable(): void
    {
        $response = $this->client()->request('GET', $this->upstream->url.'/orders?limit=500', ['buffer' => false]);
        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('too big', $response->getContent(false));
        self::assertSame([], $this->manifest->heals());
    }

    public function testStreamingAHealedResponseUsesTheRetryAndPreservesIdentity(): void
    {
        $this->healTo(['limit' => 100]);
        $client = $this->client();
        $response = $client->request('POST', $this->upstream->url.'/orders', ['json' => ['limit' => 500]]);
        self::assertSame(200, $response->getStatusCode());
        $body = '';
        foreach ($client->stream($response) as $key => $chunk) {
            self::assertSame($response, $key);
            $body .= $chunk->getContent();
        }
        self::assertStringContainsString('"limit":100', $body);
    }

    public function testConcurrentRequestsStreamWithoutError(): void
    {
        $this->healTo(['limit' => 100]);
        $client = $this->client();
        $responses = [
            $client->request('GET', $this->upstream->url.'/ping'),
            $client->request('GET', $this->upstream->url.'/ping'),
        ];
        $completed = 0;
        foreach ($client->stream($responses) as $key => $chunk) {
            self::assertContains($key, $responses);
            if ($chunk->isLast()) {
                $completed++;
            }
        }
        self::assertSame(2, $completed, 'wrapped responses still stream through the transport');
    }

    public function testTheRetryIsNotItselfCaptured(): void
    {
        $this->healTo(['limit' => 100]);

        $this->client()->request('POST', $this->upstream->url.'/orders', ['json' => ['limit' => 500]])->getStatusCode();

        self::assertCount(1, $this->manifest->heals(), 'the retry must not be captured as a new failure');
    }

    public function testARelativeUrlOnABaseUriClientIsCapturedAbsolute(): void
    {
        $this->healTo(['limit' => 100]);

        $response = $this->client(['base_uri' => $this->upstream->url])->request('POST', '/orders', ['json' => ['limit' => 500]]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($this->upstream->url.'/orders', $this->manifest->heals()[0]['request']['url']);
    }

    /**
     * Mirrors real FrameworkBundle wiring: http_client and every scoped client
     * (here, api.client) are built on top of http_client.transport, not on
     * http_client itself.
     */
    public function testTheBundleDecoratesTheHttpClientServiceAndItsScopedClients(): void
    {
        $container = new \Symfony\Component\DependencyInjection\ContainerBuilder();
        $container->register('http_client.transport', HttpClientInterface::class)
            ->setFactory([HttpClient::class, 'create'])
            ->setPublic(true);
        $container->register('http_client', \Symfony\Component\HttpClient\ScopingHttpClient::class)
            ->setFactory([\Symfony\Component\HttpClient\ScopingHttpClient::class, 'forBaseUri'])
            ->setArguments([new \Symfony\Component\DependencyInjection\Reference('http_client.transport'), $this->upstream->url])
            ->setPublic(true);
        $container->register('api.client', \Symfony\Component\HttpClient\ScopingHttpClient::class)
            ->setFactory([\Symfony\Component\HttpClient\ScopingHttpClient::class, 'forBaseUri'])
            ->setArguments([new \Symfony\Component\DependencyInjection\Reference('http_client.transport'), $this->upstream->url])
            ->setPublic(true);
        (new \Mnfst\Symfony\ManifestBundle())->build($container);
        $container->compile();

        self::assertInstanceOf(HealingHttpClient::class, $container->get('http_client.transport'));

        $this->healTo(['limit' => 100]);
        $response = $container->get('api.client')->request('POST', '/orders', ['json' => ['limit' => 500]]);
        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $this->manifest->heals());

        $response = $container->get('http_client')->request('POST', '/orders', ['json' => ['limit' => 500]]);
        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $this->manifest->heals(), 'a request through http_client itself heals too');
    }

    /** Without a transport service, the fallback decorates http_client directly. */
    public function testTheBundleDecoratesHttpClientDirectlyWhenThereIsNoTransport(): void
    {
        $container = new \Symfony\Component\DependencyInjection\ContainerBuilder();
        $container->register('http_client', HttpClientInterface::class)
            ->setFactory([HttpClient::class, 'create'])
            ->setPublic(true);
        (new \Mnfst\Symfony\ManifestBundle())->build($container);
        $container->compile();

        self::assertInstanceOf(HealingHttpClient::class, $container->get('http_client'));

        $this->healTo(['limit' => 100]);
        $response = $container->get('http_client')->request('POST', $this->upstream->url.'/orders', ['json' => ['limit' => 500]]);
        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $this->manifest->heals());
    }

    public function testTheBundleDoesNothingWithoutAnHttpClientService(): void
    {
        $container = new \Symfony\Component\DependencyInjection\ContainerBuilder();
        (new \Mnfst\Symfony\ManifestBundle())->build($container);
        $container->compile();

        self::assertFalse($container->has('mnfst.http_client'));
    }
}
