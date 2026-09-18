<?php declare(strict_types=1);

namespace Mnfst\Tests\Unit;

use Mnfst\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GateTest extends TestCase
{
    public static function statuses(): array
    {
        return [
            'bad request'   => [400, true],
            'unprocessable' => [422, true],
            'conflict'      => [409, true],
            'payload'       => [413, true],
            'unauthorized'  => [401, false],
            'payment'       => [402, false],
            'forbidden'     => [403, false],
            'rate limited'  => [429, false],
            'server fault'  => [500, false],
            'ok'            => [200, false],
            'redirect'      => [302, false],
        ];
    }

    #[DataProvider('statuses')]
    public function testStatusGate(int $status, bool $expected): void
    {
        self::assertSame($expected, Gate::shouldCapture($status));
    }

    public function testParsesJsonObject(): void
    {
        self::assertSame(['limit' => 500], Gate::parseJsonBody('{"limit":500}'));
    }

    public function testRejectsBodyOverTheLimit(): void
    {
        $huge = json_encode(['pad' => str_repeat('x', Gate::REQUEST_BODY_LIMIT)]);
        self::assertNull(Gate::parseJsonBody($huge));
    }

    public function testRejectsNonJson(): void
    {
        self::assertNull(Gate::parseJsonBody('not json at all'));
    }

    public function testRejectsAbsentBody(): void
    {
        self::assertNull(Gate::parseJsonBody(null));
        self::assertNull(Gate::parseJsonBody(''));
    }

    public function testRejectsExcessiveNesting(): void
    {
        $deep = str_repeat('[', 200) . str_repeat(']', 200);
        self::assertNull(Gate::parseJsonBody($deep));
    }
}
