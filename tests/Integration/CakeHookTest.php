<?php declare(strict_types=1);

namespace Mnfst\Tests\Integration;

use Cake\Http\Client;
use Mnfst\Config;
use Mnfst\HealApi;
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

        $config = Config::resolve('k', $this->manifest->url);
        Cake::install($config, new HealApi($config));
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
}
