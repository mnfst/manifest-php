<?php declare(strict_types=1);

namespace Mnfst\Tests\Unit;

use Mnfst\Merge;
use PHPUnit\Framework\TestCase;

final class MergeTest extends TestCase
{
    public function testHealedObjectDecidesEveryKeyItNames(): void
    {
        self::assertSame(['limit' => 100], Merge::healedBody(['limit' => 500], ['limit' => 500], ['limit' => 100]));
    }

    public function testAKeyTheHealedObjectOmitsIsDropped(): void
    {
        $out = Merge::healedBody(['limit' => 500, 'extra' => 1], ['limit' => 500, 'extra' => 1], ['limit' => 100]);
        self::assertSame(['limit' => 100], $out);
    }

    public function testWithheldCredentialsAreRestored(): void
    {
        $out = Merge::healedBody(['limit' => 500, 'api_key' => 'sk_live_1'], ['limit' => 500], ['limit' => 100]);
        self::assertSame(['limit' => 100, 'api_key' => 'sk_live_1'], $out);
    }

    public function testHealedObjectMayOverrideAWithheldCredential(): void
    {
        self::assertSame(['api_key' => 'new'], Merge::healedBody(['api_key' => 'old'], [], ['api_key' => 'new']));
    }

    public function testNonObjectBodiesAreReplacedWholesale(): void
    {
        self::assertSame([1, 2], Merge::healedBody(['a' => 1], ['a' => 1], [1, 2]));
        self::assertSame('text', Merge::healedBody(['a' => 1], ['a' => 1], 'text'));
        self::assertSame(['a' => 1], Merge::healedBody('text', 'text', ['a' => 1]));
    }
}
