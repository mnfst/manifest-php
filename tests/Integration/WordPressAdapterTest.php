<?php declare(strict_types=1);

namespace Mnfst\Tests\Integration;

use Mnfst\Tests\Support\StubManifest;
use Mnfst\Tests\Support\StubUpstream;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

use function Mnfst\manifest;
use function Mnfst\WordPress\listen;

/** Manifest's state and WordPress's filters are process-global, so each test gets a process. */
#[RunTestsInSeparateProcesses]
final class WordPressAdapterTest extends TestCase
{
    private StubManifest $manifest;
    private StubUpstream $upstream;

    protected function setUp(): void
    {
        $this->manifest = new StubManifest();
        $this->manifest->start();
        $this->upstream = new StubUpstream();
        $this->upstream->start();

        require_once __DIR__ . '/../Support/wordpress.php';
        listen();
        manifest('k', $this->manifest->url);
    }

    protected function tearDown(): void
    {
        $this->manifest->stop();
        $this->upstream->stop();
    }

    public function testHealsAFailingJsonRequest(): void
    {
        $this->manifest->setResult(['status' => 'patched', 'healAttemptId' => 'a1', 'healedRequest' => ['body' => ['limit' => 100]]]);

        $response = wp_remote_post($this->upstream->url.'/orders', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['limit' => 500]),
        ]);

        self::assertSame(200, $response['response']['code']);
        self::assertStringContainsString('"limit":100', $response['body']);
        self::assertSame([['a1', ['response' => ['statusCode' => 200]]]], $this->manifest->outcomes());
    }

    public function testHealsAFailingFormRequest(): void
    {
        $this->manifest->setResult(['status' => 'patched', 'healAttemptId' => 'a1', 'healedRequest' => ['body' => ['limit' => '100']]]);

        // Array $data is Requests' form shape; the heal and retry must keep it a form.
        $response = wp_remote_post($this->upstream->url.'/orders', ['body' => ['limit' => '500']]);

        self::assertSame(200, $response['response']['code']);
        $sent = $this->manifest->heals()[0]['request'];
        self::assertSame(['limit' => '500'], $sent['body']);
    }

    public function testQueryDataIsCapturedAndRetriedInTheUrl(): void
    {
        $this->manifest->setResult(['status' => 'patched', 'healAttemptId' => 'a1', 'healedRequest' => [
            'url' => $this->upstream->url.'/search?query=Batman',
            'headers' => ['X-Old' => null, 'X-New' => 'patched'],
        ]]);
        $response = wp_remote_get($this->upstream->url.'/search?page=1', ['headers' => ['X-Old' => 'remove'], 'body' => ['page' => 2]]);

        self::assertSame(200, $response['response']['code']);
        $echo = json_decode($response['body'], true);
        self::assertSame('query=Batman', $echo['query']);
        self::assertSame('GET', $echo['method']);
        self::assertSame('', $echo['body']);
        self::assertArrayNotHasKey('x-old', $echo['headers']);
        self::assertSame('patched', $echo['headers']['x-new']);
        self::assertStringEndsWith('page=1&page=2', $this->manifest->heals()[0]['request']['url']);
        self::assertNull($this->manifest->heals()[0]['request']['body']);
    }

    public function testTimingIncludesTheOriginalRequest(): void
    {
        wp_remote_get($this->upstream->url.'/slow');
        self::assertGreaterThanOrEqual(20, $this->manifest->heals()[0]['responseTimeMs']);
    }

    public function testASuccessfulResponsePassesThroughUntouched(): void
    {
        $response = wp_remote_post($this->upstream->url.'/orders', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['limit' => 5]),
        ]);

        self::assertSame(200, $response['response']['code']);
        self::assertSame([], $this->manifest->heals());
    }

    public function testNoPatchReturnsTheOriginalResponse(): void
    {
        $this->manifest->setResult(['status' => 'no_patch']);

        $response = wp_remote_post($this->upstream->url.'/orders', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['limit' => 500]),
        ]);

        self::assertSame(400, $response['response']['code']);
        self::assertStringContainsString('too big', $response['body']);
        self::assertCount(1, $this->manifest->heals());
    }

    public function testAForbiddenStatusIsNeverCaptured(): void
    {
        wp_remote_get($this->upstream->url.'/unauthorized');

        self::assertSame([], $this->manifest->heals());
    }

    public function testTheReportedRequestCarriesMethodUrlAndMaskedHeaders(): void
    {
        $this->manifest->setResult(['status' => 'no_patch']);

        wp_remote_post($this->upstream->url.'/orders', [
            'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer sk_live'],
            'body' => json_encode(['limit' => 500]),
        ]);

        $sent = $this->manifest->heals()[0]['request'];
        self::assertSame('POST', $sent['method']);
        self::assertStringEndsWith('/orders', $sent['url']);
        self::assertSame('Bearer REDACTED', $sent['headers']['authorization']);
        self::assertSame(['limit' => 500], $sent['body']);
    }

    public function testAHealedUrlOnAnotherOriginIsNotReplayed(): void
    {
        $this->manifest->setResult(['status' => 'patched', 'healAttemptId' => 'a1', 'healedRequest' => [
            'url' => 'http://127.0.0.1:9/orders',   // same origin rule: rejected before sending
        ]]);

        $response = wp_remote_post($this->upstream->url.'/orders', ['headers' => ['Content-Type' => 'application/json'], 'body' => '{"limit":500}']);

        self::assertSame(400, $response['response']['code']);
    }

    public function testAPreemptedCallIsLeftAlone(): void
    {
        add_filter('pre_http_request', static fn (): array => ['response' => ['code' => 418], 'body' => ''], 5);

        $response = wp_remote_get($this->upstream->url.'/orders');

        self::assertSame(418, $response['response']['code']);
        self::assertSame([], $this->manifest->heals());
    }
}
