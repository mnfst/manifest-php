<?php declare(strict_types=1);

namespace Mnfst\Tests\Integration;

use GuzzleHttp\Client;
use Mnfst\Manifest;
use Mnfst\Tests\Support\StubManifest;
use Mnfst\Tests\Support\StubUpstream;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

use function Mnfst\manifest;

/**
 * A test suite fakes its HTTP; the SDK must not report those faked failures.
 * Hooks are process-global, so each test gets a process.
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

    public function testTheRunnerIsDetected(): void
    {
        self::assertTrue(Manifest::inTestRunner());
    }

    public function testManifestInstallsNothingUnderATestRunnerByDefault(): void
    {
        putenv('MNFST_IN_TESTS');
        unset($_ENV['MNFST_IN_TESTS'], $_SERVER['MNFST_IN_TESTS']);

        manifest('k', $this->manifest->url);
        (new Client(['http_errors' => false]))->post($this->upstream->url.'/orders', ['json' => ['limit' => 500]]);

        self::assertSame([], $this->manifest->heals(), 'a faked failure in a test suite must not be reported');
        self::assertSame([], $this->manifest->hellos(), 'no handshake either');
    }

    public function testAnAlreadyInstalledHookStopsCapturingWhenTestsDisableIt(): void
    {
        manifest('k', $this->manifest->url);
        putenv('MNFST_IN_TESTS');
        unset($_ENV['MNFST_IN_TESTS'], $_SERVER['MNFST_IN_TESTS']);
        $mock = new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(400)]);
        $client = new Client(['handler' => \GuzzleHttp\HandlerStack::create($mock), 'http_errors' => false]);
        self::assertSame(400, $client->get('https://fake.test/')->getStatusCode());
        self::assertSame([], $this->manifest->heals());
    }

    public function testLaravelFakeFailuresNeverReachManifestByDefault(): void
    {
        putenv('MNFST_IN_TESTS');
        unset($_ENV['MNFST_IN_TESTS'], $_SERVER['MNFST_IN_TESTS']);
        \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory());
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['error' => 'fake'], 404)]);
        manifest('k', $this->manifest->url);

        $response = \Illuminate\Support\Facades\Http::get('https://api.example/fake');
        self::assertSame(404, $response->status());
        self::assertSame([], $this->manifest->heals());
        self::assertSame([], $this->manifest->hellos());
        \Illuminate\Support\Facades\Http::assertSentCount(1);
    }

    public function testMnfstInTestsOptsBackIn(): void
    {
        putenv('MNFST_IN_TESTS=1');
        $_SERVER['MNFST_IN_TESTS'] = '1';
        $this->manifest->setResult(['status' => 'patched', 'healAttemptId' => 'a1', 'healedRequest' => ['body' => ['limit' => 100]]]);

        manifest('k', $this->manifest->url);
        $response = (new Client(['http_errors' => false]))->post($this->upstream->url.'/orders', ['json' => ['limit' => 500]]);

        self::assertSame(200, $response->getStatusCode(), 'integration tests can still heal when opted in');
        self::assertCount(1, $this->manifest->heals());

        putenv('MNFST_IN_TESTS');
        unset($_SERVER['MNFST_IN_TESTS']);
    }
}
