<?php declare(strict_types=1);

namespace Mnfst\Tests\Integration;

use Mnfst\Config;
use Mnfst\HealApi;
use Mnfst\Hooks\WordPress;
use Mnfst\Tests\Support\StubManifest;
use Mnfst\Tests\Support\StubUpstream;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WpOrg\Requests\Requests;

/** Hooks are process-global, so each test gets a process. */
#[RunTestsInSeparateProcesses]
final class WordPressHookTest extends TestCase
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
        WordPress::install($config, new HealApi($config));
    }

    protected function tearDown(): void
    {
        $this->manifest->stop();
        $this->upstream->stop();
    }

    public function testHealsAFailingJsonRequest(): void
    {
        $this->manifest->setResult(['status' => 'patched', 'healAttemptId' => 'a1', 'healedRequest' => ['body' => ['limit' => 100]]]);

        $response = Requests::post(
            $this->upstream->url.'/orders',
            ['Content-Type' => 'application/json'],
            json_encode(['limit' => 500]),
        );

        self::assertSame(200, $response->status_code);
        self::assertStringContainsString('"limit":100', $response->body);
        self::assertSame([['a1', ['response' => ['statusCode' => 200]]]], $this->manifest->outcomes());
    }

    public function testHealsAFailingFormRequest(): void
    {
        $this->manifest->setResult(['status' => 'patched', 'healAttemptId' => 'a1', 'healedRequest' => ['body' => ['limit' => '100']]]);

        // Array $data is Requests' form shape; the heal and retry must keep it a form.
        $response = Requests::post($this->upstream->url.'/orders', [], ['limit' => '500']);

        self::assertSame(200, $response->status_code);
        $sent = $this->manifest->heals()[0]['request'];
        self::assertSame(['limit' => '500'], $sent['body']);
    }

    public function testASuccessfulResponsePassesThroughUntouched(): void
    {
        $response = Requests::post($this->upstream->url.'/orders', ['Content-Type' => 'application/json'], json_encode(['limit' => 5]));

        self::assertSame(200, $response->status_code);
        self::assertSame([], $this->manifest->heals());
    }

    public function testNoPatchReturnsTheOriginalResponse(): void
    {
        $this->manifest->setResult(['status' => 'no_patch']);

        $response = Requests::post($this->upstream->url.'/orders', ['Content-Type' => 'application/json'], json_encode(['limit' => 500]));

        self::assertSame(400, $response->status_code);
        self::assertStringContainsString('too big', $response->body);
        self::assertCount(1, $this->manifest->heals());
    }

    public function testAForbiddenStatusIsNeverCaptured(): void
    {
        Requests::get($this->upstream->url.'/unauthorized');

        self::assertSame([], $this->manifest->heals());
    }

    public function testTheReportedRequestCarriesMethodUrlAndMaskedHeaders(): void
    {
        $this->manifest->setResult(['status' => 'no_patch']);

        Requests::post(
            $this->upstream->url.'/orders',
            ['Content-Type' => 'application/json', 'Authorization' => 'Bearer sk_live'],
            json_encode(['limit' => 500]),
        );

        $sent = $this->manifest->heals()[0]['request'];
        self::assertSame('POST', $sent['method']);
        self::assertStringEndsWith('/orders', $sent['url']);
        self::assertSame('REDACTED', $sent['headers']['authorization']);
        self::assertSame(['limit' => 500], $sent['body']);
    }
}
