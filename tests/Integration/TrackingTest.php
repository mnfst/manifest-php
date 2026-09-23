<?php declare(strict_types=1);

namespace Mnfst\Tests\Integration;

use Mnfst\Config;
use Mnfst\HealApi;
use Mnfst\Tests\Support\StubManifest;
use Mnfst\Tracking;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/** The spool behind tracked calls (see Tracking). Each test gets a fresh stub, so a fresh spool. */
#[RunTestsInSeparateProcesses]
final class TrackingTest extends TestCase
{
    private StubManifest $manifest;
    private Config $config;
    private Tracking $tracking;

    protected function setUp(): void
    {
        $this->manifest = new StubManifest();
        $this->manifest->start();
        $this->config = Config::resolve('k', $this->manifest->url);
        $this->tracking = new Tracking($this->config, new HealApi($this->config));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tracking->spoolPath() . '*') ?: [] as $file) {
            @unlink($file);
        }
        @unlink($this->tracking->sentPath());
        $this->manifest->stop();
    }

    private function record(int $count, int $status = 200): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->tracking->record('get', "https://user:pw@api.example.com/items/$i?token=secret#frag", $status, microtime(true) - 0.05);
        }
    }

    /** @return list<string> */
    private function spoolLines(): array
    {
        return @file($this->tracking->spoolPath(), FILE_IGNORE_NEW_LINES) ?: [];
    }

    public function testRecordingAppendsOneOwnerOnlyLineWithoutTheQuery(): void
    {
        $this->record(1);

        $lines = $this->spoolLines();
        self::assertCount(1, $lines);
        self::assertSame(0600, fileperms($this->tracking->spoolPath()) & 0777);
        $call = json_decode($lines[0], true);
        self::assertSame(['traceId', 'method', 'url', 'statusCode', 'responseTimeMs', 'occurredAt'], array_keys($call));
        self::assertSame('GET', $call['method']);
        self::assertSame('https://api.example.com/items/0', $call['url']);
        self::assertSame(200, $call['statusCode']);
        self::assertGreaterThanOrEqual(50, $call['responseTimeMs']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $call['occurredAt']);
        self::assertSame([], $this->manifest->batches());   // recording never reaches the network
    }

    public function testOutOfRangeRecordsAreNeverWritten(): void
    {
        $this->tracking->record(str_repeat('X', 17), 'https://api.example.com/x', 200, microtime(true));
        $this->tracking->record('GET', 'https://api.example.com/' . str_repeat('a', 4100), 200, microtime(true));
        $this->tracking->record('GET', 'mailto:a@b.co', 200, microtime(true));
        $this->tracking->record('GET', 'https://api.example.com/x', 0, microtime(true));

        self::assertSame([], $this->spoolLines());
    }

    public function testTheSpoolStopsGrowingAtItsCap(): void
    {
        $line = str_repeat('x', 1000) . "\n";
        file_put_contents($this->tracking->spoolPath(), str_repeat($line, (int) (Tracking::SPOOL_CAP_BYTES / 1001)));
        $before = filesize($this->tracking->spoolPath());
        $this->record(5);
        clearstatcache();

        self::assertSame($before, filesize($this->tracking->spoolPath()));
    }

    public function testFlushSendsBatchesOf500AndEmptiesTheSpool(): void
    {
        $this->record(1200);
        $this->tracking->flush();

        self::assertSame([500, 500, 200], array_column($this->manifest->batches(), 'count'));
        self::assertCount(1200, $this->manifest->tracked());
        self::assertFileDoesNotExist($this->tracking->spoolPath());
    }

    public function testARetryableAnswerIsRetriedOnceThenDropped(): void
    {
        $this->manifest->setRequestsStatus(503);
        $this->record(3);
        $this->tracking->flush();

        self::assertSame([503, 503], array_column($this->manifest->batches(), 'status'));
        self::assertFileDoesNotExist($this->tracking->spoolPath());
    }

    public function testAServerWithoutTheRouteIsNotRetried(): void
    {
        $this->manifest->setRequestsStatus(404);
        $this->record(3);
        $this->tracking->flush();

        self::assertSame([404], array_column($this->manifest->batches(), 'status'));
    }

    public function testADisabledProjectPausesSending(): void
    {
        $this->manifest->setDisabled(true);
        $this->record(2);
        $this->tracking->flush();
        $this->manifest->setDisabled(false);
        $this->record(2);
        $this->tracking->flushIfDue();

        self::assertSame([], $this->manifest->tracked());
        self::assertCount(2, $this->spoolLines());   // kept for after the pause
    }

    public function testASendNeverOutlastsItsBudget(): void
    {
        $this->record(3);
        $started = microtime(true);
        // A black-holed server: nothing listens on this port, and the budget bounds the connect.
        $config = Config::resolve('k', 'http://10.255.255.1:9');
        $slow = new Tracking($config, new HealApi($config));
        copy($this->tracking->spoolPath(), $slow->spoolPath());
        $slow->flush(0.3);
        @unlink($slow->spoolPath());

        self::assertLessThan(1.5, microtime(true) - $started);
    }

    public function testOnlyOneProcessClaimsTheSpool(): void
    {
        $this->record(10);
        $second = new Tracking($this->config, new HealApi($this->config));
        $this->tracking->flush();
        $second->flush();

        self::assertCount(1, $this->manifest->batches());
        self::assertCount(10, $this->manifest->tracked());
    }

    public function testFlushIfDueWaitsForTheGapAndTheBatch(): void
    {
        touch($this->tracking->sentPath());   // just sent
        $this->record(3);
        $this->tracking->flushIfDue();
        self::assertSame([], $this->manifest->batches());   // under a second since the last send

        touch($this->tracking->sentPath(), time() - 2);
        $this->tracking->flushIfDue();
        self::assertSame([], $this->manifest->batches());   // small spool, last send under 5 s ago

        touch($this->tracking->sentPath(), time() - 6);
        $this->tracking->flushIfDue();
        self::assertCount(3, $this->manifest->tracked());
    }

    public function testAClaimLeftByACrashedProcessIsRemoved(): void
    {
        $stale = $this->tracking->spoolPath() . '.999-dead.sending';
        file_put_contents($stale, "{}\n");
        touch($stale, time() - Tracking::STALE_CLAIM_SECONDS - 5);
        $this->record(1);
        $this->tracking->flush();

        self::assertFileDoesNotExist($stale);
    }

    public function testConcurrentProcessesLoseAndDuplicateNothing(): void
    {
        // A last send "in the future" keeps every child's shutdown send from
        // being due, so the spool is left for the assertion.
        touch($this->tracking->sentPath(), time() + 3600);
        $children = [];
        for ($i = 0; $i < 8; $i++) {
            $children[] = proc_open(
                [PHP_BINARY, __DIR__ . '/../Support/record-calls.php', $this->manifest->url, '250', 'no-flush'],
                [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
            );
        }
        foreach ($children as $child) {
            proc_close($child);
        }
        $lines = $this->spoolLines();
        self::assertCount(2000, $lines);
        self::assertCount(2000, array_unique(array_map(static fn (string $l): string => json_decode($l, true)['traceId'], $lines)));
    }

    public function testAProcessThatEndsSendsTheSpoolAtShutdown(): void
    {
        $child = proc_open(
            [PHP_BINARY, __DIR__ . '/../Support/record-calls.php', $this->manifest->url, '3', 'flush-at-exit'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        self::assertSame(0, proc_close($child));

        $urls = array_column($this->manifest->tracked(), 'url');
        self::assertSame(['https://api.example.com/items/0', 'https://api.example.com/items/1', 'https://api.example.com/items/2'], $urls);
    }
}
