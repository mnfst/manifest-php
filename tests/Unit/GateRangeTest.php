<?php declare(strict_types=1);

namespace Mnfst\Tests\Unit;

use Mnfst\Gate;
use PHPUnit\Framework\TestCase;

/**
 * Exhaustive status-gate coverage across the whole 100-599 range, plus the
 * parseJsonBody size and depth boundaries GateTest only samples.
 */
final class GateRangeTest extends TestCase
{
    public function testCapturesEveryRepairable4xxAcrossTheFullRange(): void
    {
        for ($status = 100; $status <= 599; $status++) {
            $repairable = $status >= 400
                && $status < 500
                && !in_array($status, Gate::FORBIDDEN_STATUSES, true);

            self::assertSame($repairable, Gate::shouldCapture($status), "status $status");
        }
    }

    public function testTheForbiddenStatusesAreNeverCaptured(): void
    {
        foreach (Gate::FORBIDDEN_STATUSES as $status) {
            self::assertFalse(Gate::shouldCapture($status), "status $status is forbidden");
        }
        self::assertSame([401, 402, 403, 429], Gate::FORBIDDEN_STATUSES);
    }

    public function testTheRangeBoundariesGateCorrectly(): void
    {
        self::assertFalse(Gate::shouldCapture(399), 'below 4xx');
        self::assertTrue(Gate::shouldCapture(400), 'first repairable status');
        self::assertTrue(Gate::shouldCapture(499), 'last 4xx');
        self::assertFalse(Gate::shouldCapture(500), 'first 5xx');
    }

    public function testParsesScalarsAndLists(): void
    {
        self::assertSame(42, Gate::parseJsonBody('42'));
        self::assertTrue(Gate::parseJsonBody('true'));
        self::assertSame('text', Gate::parseJsonBody('"text"'));
        self::assertSame([1, 2, 3], Gate::parseJsonBody('[1,2,3]'));
    }

    public function testALiteralNullBodyIsIndistinguishableFromRejection(): void
    {
        // json "null" decodes to null, the same value parseJsonBody returns when it gives up.
        self::assertNull(Gate::parseJsonBody('null'));
    }

    public function testWhitespaceOnlyBodyIsRejected(): void
    {
        self::assertNull(Gate::parseJsonBody('   '));
    }

    public function testAcceptsABodyExactlyAtTheLimitButRejectsOneByteOver(): void
    {
        $atLimit = '"' . str_repeat('a', Gate::REQUEST_BODY_LIMIT - 2) . '"';
        self::assertSame(Gate::REQUEST_BODY_LIMIT, strlen($atLimit));
        self::assertSame(str_repeat('a', Gate::REQUEST_BODY_LIMIT - 2), Gate::parseJsonBody($atLimit));

        $overLimit = '"' . str_repeat('a', Gate::REQUEST_BODY_LIMIT - 1) . '"';
        self::assertSame(Gate::REQUEST_BODY_LIMIT + 1, strlen($overLimit));
        self::assertNull(Gate::parseJsonBody($overLimit), 'one byte past the limit is refused');
    }

    public function testAcceptsNestingWithinTheDepthLimitAndRejectsBeyondIt(): void
    {
        // json_decode with depth 64 admits 63 nested arrays and throws on the 64th.
        $within = str_repeat('[', 63) . str_repeat(']', 63);
        self::assertNotNull(Gate::parseJsonBody($within));

        $beyond = str_repeat('[', 64) . str_repeat(']', 64);
        self::assertNull(Gate::parseJsonBody($beyond), 'over-deep nesting fails open to null');
    }
}
