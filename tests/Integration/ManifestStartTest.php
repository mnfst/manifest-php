<?php declare(strict_types=1);

namespace Mnfst\Tests\Integration;

use Cake\Http\Client;
use Mnfst\Tests\Support\StubManifest;
use Mnfst\Tests\Support\StubUpstream;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

use function Mnfst\manifest;

/** Hooks are process-global, so each test gets a process. */
#[RunTestsInSeparateProcesses]
final class ManifestStartTest extends TestCase
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

    public function testASecondCallIsSilentAndKeepsHealing(): void
    {
        $this->manifest->setResult(['status' => 'patched', 'healAttemptId' => 'a1', 'healedRequest' => ['body' => ['limit' => 100]]]);
        set_error_handler(static function (int $level, string $message): bool {
            if (!(error_reporting() & $level)) {
                return false;   // @-suppressed, as Laravel's handler treats it
            }
            throw new \RuntimeException("PHP error {$level}: {$message}");   // what Laravel's handler does with any warning
        });

        try {
            manifest('k', $this->manifest->url);
            manifest('k', $this->manifest->url);   // the app booted again in the same process (a test runner, a prepend + bootstrap pair)
            $response = (new Client())->post($this->upstream->url . '/orders', json_encode(['limit' => 500]), ['type' => 'json']);
        } finally {
            restore_error_handler();
        }

        self::assertSame(200, $response->getStatusCode(), 'still healed after the second call');
        self::assertCount(1, $this->manifest->heals(), 'one hook, one capture');
        self::assertCount(1, $this->manifest->hellos(), 'the handshake is announced once');
    }

    public function testClearingTheKeyDisablesAlreadyInstalledHooks(): void
    {
        manifest('k', $this->manifest->url);
        manifest('', $this->manifest->url);
        (new Client())->post($this->upstream->url.'/orders', '{"limit":500}', ['type' => 'json']);

        self::assertSame([], $this->manifest->heals());
    }

    public function testALaterCallRefreshesTheConfiguration(): void
    {
        $other = new StubManifest();
        $other->start();
        $other->setResult(['status' => 'no_patch']);
        try {
            manifest('k', $this->manifest->url);
            manifest('k', $other->url);
            (new Client())->post($this->upstream->url . '/orders', json_encode(['limit' => 500]), ['type' => 'json']);

            self::assertCount(0, $this->manifest->heals());
            self::assertCount(1, $other->heals(), 'the capture went to the server of the latest call');
        } finally {
            $other->stop();
        }
    }
}
