<?php declare(strict_types=1);

namespace Mnfst\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Mnfst\Manifest;
use Mnfst\Tests\Support\StubManifest;
use Mnfst\Tests\Support\StubUpstream;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

use function Mnfst\Guzzle\middleware;
use function Mnfst\manifest;

/**
 * A test suite fakes its HTTP; the SDK must not report those faked failures.
 * Manifest's state is process-global, so each test gets a process.
 */
#[RunTestsInSeparateProcesses]
final class TestRunnerGuardTest extends TestCase
{
    private StubManifest $manifest;
    private StubUpstream $upstream;

    protected function setUp(): void
    {
        $this->manifest = new StubManifest();
        $this->manifest->start();
        $this->upstream = new StubUpstream();
        $this->upstream->start();
    }

    protected function tearDown(): void
    {
        $this->manifest->stop();
        $this->upstream->stop();
    }

    /** A client the way an app wires it: its own handler stack, plus the middleware. */
    private function client(array $config = []): Client
    {
        $stack = $config['handler'] ?? HandlerStack::create();
        $stack->push(middleware());

        return new Client(['handler' => $stack] + $config);
    }

    public function testTheRunnerIsDetected(): void
    {
        self::assertTrue(Manifest::inTestRunner());
    }

    public function testManifestInstallsNothingUnderATestRunnerByDefault(): void
    {
        putenv('MNFST_IN_TESTS');
        unset($_ENV['MNFST_IN_TESTS'], $_SERVER['MNFST_IN_TESTS']);

        manifest('k', $this->manifest->url);
        ($this->client(['http_errors' => false]))->post($this->upstream->url.'/orders', ['json' => ['limit' => 500]]);

        self::assertSame([], $this->manifest->heals(), 'a faked failure in a test suite must not be reported');
        self::assertSame([], $this->manifest->hellos(), 'no handshake either');
    }

    public function testAStartedSdkStopsCapturingWhenTestsDisableIt(): void
    {
        manifest('k', $this->manifest->url);
        putenv('MNFST_IN_TESTS');
        unset($_ENV['MNFST_IN_TESTS'], $_SERVER['MNFST_IN_TESTS']);
        $mock = new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(400)]);
        $client = $this->client(['handler' => \GuzzleHttp\HandlerStack::create($mock), 'http_errors' => false]);
        self::assertSame(400, $client->get('https://fake.test/')->getStatusCode());
        self::assertSame([], $this->manifest->heals());
    }

    public function testMnfstInTestsOptsBackIn(): void
    {
        putenv('MNFST_IN_TESTS=1');
        $_SERVER['MNFST_IN_TESTS'] = '1';
        $this->manifest->setResult(['status' => 'patched', 'healAttemptId' => 'a1', 'healedRequest' => ['body' => ['limit' => 100]]]);

        manifest('k', $this->manifest->url);
        $response = ($this->client(['http_errors' => false]))->post($this->upstream->url.'/orders', ['json' => ['limit' => 500]]);

        self::assertSame(200, $response->getStatusCode(), 'integration tests can still heal when opted in');
        self::assertCount(1, $this->manifest->heals());

        putenv('MNFST_IN_TESTS');
        unset($_SERVER['MNFST_IN_TESTS']);
    }
}
