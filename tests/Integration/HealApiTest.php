<?php declare(strict_types=1);

namespace Mnfst\Tests\Integration;

use Mnfst\Config;
use Mnfst\HealApi;
use Mnfst\Tests\Support\StubManifest;
use PHPUnit\Framework\TestCase;

final class HealApiTest extends TestCase
{
    private StubManifest $stub;

    protected function setUp(): void
    {
        $this->stub = new StubManifest();
        $this->stub->start();
    }

    protected function tearDown(): void
    {
        $this->stub->stop();
    }

    private function api(): HealApi
    {
        return new HealApi(Config::resolve('mnfx_test', $this->stub->url));
    }

    private function payload(): array
    {
        return [
            'traceId' => 't1',
            'request' => ['method' => 'POST', 'url' => 'https://api.test/orders', 'headers' => [], 'body' => ['limit' => 500]],
            'response' => ['statusCode' => 400, 'body' => ['error' => 'too big'], 'truncated' => false],
            'responseTimeMs' => 5,
        ];
    }

    public function testHealRoundTrip(): void
    {
        self::assertSame(['status' => 'no_patch', 'issueId' => 'stub-issue'], $this->api()->heal($this->payload()));
        self::assertSame([$this->payload()], $this->stub->heals());
    }

    public function testHealedRequestComesBack(): void
    {
        $this->stub->setResult([
            'status' => 'patched',
            'healAttemptId' => 'a1',
            'healedRequest' => ['body' => ['limit' => 100]],
        ]);
        $result = $this->api()->heal($this->payload());
        self::assertSame(['limit' => 100], $result['healedRequest']['body']);
    }

    public function testASuccessfulOutcomeSendsOnlyTheStatus(): void
    {
        $this->api()->reportResponse('a1', 200);
        self::assertSame([['a1', ['response' => ['statusCode' => 200]]]], $this->stub->outcomes());
    }

    public function testAFailedOutcomeSendsTheRawBody(): void
    {
        $this->api()->reportResponse('a1', 400, ['error' => 'still broken'], false);
        [[, $sent]] = $this->stub->outcomes();
        self::assertSame(['statusCode' => 400, 'body' => ['error' => 'still broken'], 'truncated' => false], $sent['response']);
    }

    public function testAFailedOutcomeSaysWhenItsBodyWasCut(): void
    {
        $this->api()->reportResponse('a1', 400, '<html>not json', true);
        [[, $sent]] = $this->stub->outcomes();
        self::assertSame(['statusCode' => 400, 'body' => '<html>not json', 'truncated' => true], $sent['response']);
    }

    public function testAnUnattemptedReplayIsReported(): void
    {
        $this->api()->reportFailure('a1', 'not_attempted', HealApi::NOT_ATTEMPTED);
        [[, $sent]] = $this->stub->outcomes();
        self::assertSame(['kind' => 'not_attempted', 'message' => 'replay_not_attempted'], $sent['failure']);
    }

    public function testATransportFailureIsReportedWithItsMessageCapped(): void
    {
        $this->api()->reportFailure('a1', 'transport_error', str_repeat('é', 400));
        [[, $sent]] = $this->stub->outcomes();
        self::assertSame('transport_error', $sent['failure']['kind']);
        self::assertLessThanOrEqual(512, strlen($sent['failure']['message']));
        self::assertTrue(mb_check_encoding($sent['failure']['message'], 'UTF-8'), 'the cap must not split a character');
    }

    public function testAFailureMessageMasksCredentialsInUrls(): void
    {
        // curl's connect errors quote the effective URL, query string included
        $this->api()->reportFailure('a1', 'transport_error', 'cURL error 7: failed for https://u:p@a.test/x?api_key=sk_live_1&page=2');
        [[, $sent]] = $this->stub->outcomes();
        self::assertStringContainsString('https://a.test/x?api_key=REDACTED&page=2', $sent['failure']['message']);
        self::assertStringNotContainsString('sk_live_1', $sent['failure']['message']);
    }

    public function testWithoutAKeyNothingIsSent(): void
    {
        $api = new HealApi(Config::resolve(null, $this->stub->url));
        self::assertNull($api->heal($this->payload()));
        $api->reportResponse('a1', 200);
        self::assertSame([], $this->stub->heals());
        self::assertSame([], $this->stub->outcomes());
    }

    public function testProjectDisabledTripsTheBackoff(): void
    {
        $this->stub->setDisabled(true);
        $api = $this->api();
        self::assertNull($api->heal($this->payload()));
        self::assertFalse($api->healingEnabled());
    }

    public function testAnUnreachableServerFailsSoft(): void
    {
        $api = new HealApi(Config::resolve('mnfx_test', 'http://127.0.0.1:9'));
        self::assertNull($api->heal($this->payload()));
    }
}
