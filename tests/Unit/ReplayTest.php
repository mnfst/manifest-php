<?php declare(strict_types=1);

namespace Mnfst\Tests\Unit;

use Mnfst\Bodies;
use Mnfst\Replay;
use PHPUnit\Framework\TestCase;

final class ReplayTest extends TestCase
{
    private const URL = 'https://a.test/orders?limit=500';

    private static function served(array $healedRequest, string $status = 'patched'): array
    {
        return ['status' => $status, 'healAttemptId' => 'a1', 'healedRequest' => $healedRequest];
    }

    public function testMergesTheHealedBodyIntoTheOriginal(): void
    {
        $plan = Replay::plan('POST', self::URL, ['limit' => 500, 'api_key' => 'k'], true, Bodies::JSON, self::served(['body' => ['limit' => 100]]));
        self::assertSame('{"limit":100,"api_key":"k"}', $plan['body']);
        self::assertSame(self::URL, $plan['url']);
        self::assertSame([], $plan['headers']);
    }

    public function testAppliesAHealedUrlOnTheSameOrigin(): void
    {
        $plan = Replay::plan('POST', self::URL, ['limit' => 500], true, Bodies::JSON, self::served(['url' => 'https://a.test/orders?limit=100']));
        self::assertSame('https://a.test/orders?limit=100', $plan['url']);
        self::assertSame('{"limit":500}', $plan['body'], 'no body key means the original body is kept');
    }

    public function testRefusesAHealedUrlOnAnotherOrigin(): void
    {
        self::assertNull(Replay::plan('POST', self::URL, ['limit' => 500], true, Bodies::JSON, self::served(['url' => 'https://evil.test/orders'])));
        self::assertNull(Replay::plan('POST', self::URL, ['limit' => 500], true, Bodies::JSON, self::served(['url' => 'https://u:p@a.test/orders'])));
    }

    public function testSetsAndRemovesHeaders(): void
    {
        $plan = Replay::plan('POST', self::URL, ['limit' => 500], true, Bodies::JSON, self::served(['headers' => ['x-limit' => '100', 'x-old' => null]]));
        self::assertSame(['x-limit' => '100', 'x-old' => null], $plan['headers']);
    }

    public function testNeverPutsTheMaskOnTheWire(): void
    {
        $plan = Replay::plan('POST', self::URL, ['limit' => 500], true, Bodies::JSON, self::served(['headers' => ['authorization' => 'REDACTED', 'x-limit' => '1']]));
        self::assertSame(['x-limit' => '1'], $plan['headers']);
    }

    public function testAGetThatMergesToNothingRetriesBodyless(): void
    {
        $plan = Replay::plan('GET', self::URL, null, true, '', self::served(['url' => 'https://a.test/orders?limit=100', 'body' => null]));
        self::assertNull($plan['body']);
        self::assertSame('https://a.test/orders?limit=100', $plan['url']);
    }

    public function testAPostThatMergesToNothingIsNotAttempted(): void
    {
        self::assertNull(Replay::plan('POST', self::URL, null, true, '', self::served(['url' => 'https://a.test/orders?limit=100', 'body' => null])));
    }

    public function testOnlyPatchedAndUnverifiedAreRetried(): void
    {
        self::assertNull(Replay::plan('POST', self::URL, ['limit' => 500], true, Bodies::JSON, self::served(['body' => ['limit' => 100]], 'no_patch')));
        self::assertNotNull(Replay::plan('POST', self::URL, ['limit' => 500], true, Bodies::JSON, self::served(['body' => ['limit' => 100]], 'unverified')));
    }

    public function testAHealedRequestThatChangesNothingIsNotAttempted(): void
    {
        self::assertNull(Replay::plan('POST', self::URL, ['limit' => 500], true, Bodies::JSON, self::served([])));
        self::assertNull(Replay::plan('POST', self::URL, ['limit' => 500], true, Bodies::JSON, ['status' => 'patched']));
    }

    public function testAnUnreplayableBodyIsNotAttempted(): void
    {
        self::assertNull(Replay::plan('POST', self::URL, null, false, 'application/octet-stream', self::served(['body' => ['limit' => 100]])));
    }

    public function testAFormBodyIsReplayedAsAForm(): void
    {
        $plan = Replay::plan('POST', self::URL, ['limit' => '500'], true, Bodies::FORM, self::served(['body' => ['limit' => 100]]));
        self::assertSame('limit=100', $plan['body']);
    }

    public function testMalformedHeadersAreNotAttempted(): void
    {
        self::assertNull(Replay::plan('POST', self::URL, ['limit' => 500], true, Bodies::JSON, self::served(['headers' => ['x' => 1]])));
        self::assertNull(Replay::plan('POST', self::URL, ['limit' => 500], true, Bodies::JSON, self::served(['headers' => 'nope'])));
    }
}
