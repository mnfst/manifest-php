<?php declare(strict_types=1);

namespace Mnfst\Tests\Unit;

use Mnfst\Bodies;
use PHPUnit\Framework\TestCase;

final class BodiesTest extends TestCase
{
    public function testReadsTheContentTypeCaseInsensitively(): void
    {
        self::assertSame('application/json', Bodies::contentTypeOf(['Content-Type' => 'application/json']));
        self::assertSame('application/json', Bodies::contentTypeOf(['content-type' => 'application/json; charset=utf-8']));
        self::assertSame('', Bodies::contentTypeOf([]));
    }

    public function testParsesJson(): void
    {
        [$body, $replayable] = Bodies::parseRequestBody('{"limit":500}', 'application/json');
        self::assertSame(['limit' => 500], $body);
        self::assertTrue($replayable);
    }

    public function testParsesFormUrlencoded(): void
    {
        [$body, $replayable] = Bodies::parseRequestBody('limit=500&page=2', 'application/x-www-form-urlencoded');
        self::assertSame(['limit' => '500', 'page' => '2'], $body);
        self::assertTrue($replayable);
    }

    public function testUnparseableBytesAreReportedButNotReplayable(): void
    {
        [$body, $replayable] = Bodies::parseRequestBody("\x00\x01binary", 'application/octet-stream');
        self::assertNull($body);
        self::assertFalse($replayable);
    }

    public function testAbsentBodyIsReplayable(): void
    {
        [$body, $replayable] = Bodies::parseRequestBody(null, '');
        self::assertNull($body);
        self::assertTrue($replayable);
    }

    public function testEncodesJson(): void
    {
        self::assertSame('{"limit":100}', Bodies::encodeRequestBody(['limit' => 100], 'application/json'));
    }

    public function testEncodesFormUrlencodedWithIndexedRepeatedKeys(): void
    {
        self::assertSame('tag%5B0%5D=a&tag%5B1%5D=b', Bodies::encodeRequestBody(['tag' => ['a', 'b']], 'application/x-www-form-urlencoded'));
    }

    public function testANonObjectBodyIsNotEncodableAsAForm(): void
    {
        self::assertNull(Bodies::encodeRequestBody('scalar', 'application/x-www-form-urlencoded'));
    }
}
