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
        @unlink($this->api()->backoffPath());
    }

    protected function tearDown(): void
    {
        @unlink($this->api()->backoffPath());
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

    public function testAnErrorBodyThatIsNotUtf8StillTravels(): void
    {
        $payload = $this->payload();
        $payload['response']['body'] = "caf\xE9 non trouv\xE9";   // Latin-1, as many legacy error pages are

        $this->api()->heal($payload);

        $received = $this->stub->heals();
        self::assertCount(1, $received, 'the capture must not be dropped');
        self::assertSame("caf\u{FFFD} non trouv\u{FFFD}", $received[0]['response']['body']);
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
        $this->api()->reportOutcome('a1', 200);
        self::assertSame([['a1', ['response' => ['statusCode' => 200]]]], $this->stub->outcomes());
    }

    public function testAFailedOutcomeSendsTheRawBody(): void
    {
        $this->api()->reportOutcome('a1', 400, ['error' => 'still broken']);
        [[, $sent]] = $this->stub->outcomes();
        self::assertSame(400, $sent['response']['statusCode']);
        self::assertSame(['error' => 'still broken'], $sent['response']['body']);
    }

    public function testAnUnattemptedReplayIsReported(): void
    {
        $this->api()->reportOutcome('a1', null, null, HealApi::NOT_ATTEMPTED);
        [[, $sent]] = $this->stub->outcomes();
        self::assertSame(['kind' => 'not_attempted', 'message' => 'replay_not_attempted'], $sent['failure']);
    }

    public function testProjectDisabledTripsTheBackoff(): void
    {
        $this->stub->setDisabled(true);
        $api = $this->api();
        self::assertNull($api->heal($this->payload()));
        self::assertFalse($api->healingEnabled());
    }

    public function testTheBackoffOutlivesTheRequest(): void
    {
        $this->stub->setDisabled(true);
        $this->api()->heal($this->payload());

        // The next php-fpm request builds its own HealApi; it must not ask again.
        $next = $this->api();
        self::assertFalse($next->healingEnabled());
        self::assertNull($next->heal($this->payload()));
        self::assertCount(1, $this->stub->requests(), 'one refused call, then silence');
        self::assertGreaterThan(time() + 200, filemtime($next->backoffPath()));
    }

    public function testARejectedKeyBacksOffToo(): void
    {
        $this->stub->setRejectKey(true);
        $this->api()->heal($this->payload());

        self::assertFalse($this->api()->healingEnabled());
        self::assertNull($this->api()->heal($this->payload()));
        self::assertCount(1, $this->stub->requests());
    }

    public function testAnUnreachableServerFailsSoftAndBacksOffBriefly(): void
    {
        $api = new HealApi(Config::resolve('mnfx_test', 'http://127.0.0.1:9'));
        @unlink($api->backoffPath());
        self::assertNull($api->heal($this->payload()));

        $deadline = filemtime($api->backoffPath());
        self::assertGreaterThan(time() + 30, $deadline);
        self::assertLessThan(time() + 120, $deadline, 'a minute, not the five of a disabled project');
        self::assertFalse((new HealApi(Config::resolve('mnfx_test', 'http://127.0.0.1:9')))->healingEnabled());
        @unlink($api->backoffPath());
    }

    public function testTheBackoffIsPerProjectAndServer(): void
    {
        $this->stub->setDisabled(true);
        $this->api()->heal($this->payload());

        self::assertTrue((new HealApi(Config::resolve('other_key', $this->stub->url)))->healingEnabled());
    }
}
