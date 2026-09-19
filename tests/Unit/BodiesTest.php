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

    public function testRepeatedFormKeysSurviveTheRoundTrip(): void
    {
        [$body] = Bodies::parseRequestBody('tag=a&tag=b', Bodies::FORM);
        self::assertSame(['tag' => ['a', 'b']], $body);
        self::assertSame('tag=a&tag=b', Bodies::encodeRequestBody($body, Bodies::FORM));
    }

    public function testBracketedFormKeysAreNotReinterpreted(): void
    {
        [$body] = Bodies::parseRequestBody('tag%5B%5D=a&tag%5B%5D=b', Bodies::FORM);
        self::assertSame(['tag[]' => ['a', 'b']], $body);
        self::assertSame('tag%5B%5D=a&tag%5B%5D=b', Bodies::encodeRequestBody($body, Bodies::FORM));
    }

    public function testFormKeysWithDotsAndSpacesSurviveTheRoundTrip(): void
    {
        [$body] = Bodies::parseRequestBody('first.name=a&user+id=b', Bodies::FORM);
        self::assertSame(['first.name' => 'a', 'user id' => 'b'], $body);
        self::assertSame('first.name=a&user+id=b', Bodies::encodeRequestBody($body, Bodies::FORM));
    }

    public function testAnEmptyJsonObjectEncodesAsAnObject(): void
    {
        [$body] = Bodies::parseRequestBody('{}', Bodies::JSON);
        self::assertSame('{}', Bodies::encodeRequestBody($body, Bodies::JSON));
    }

    public function testANonObjectBodyIsNotEncodableAsAForm(): void
    {
        self::assertNull(Bodies::encodeRequestBody('scalar', 'application/x-www-form-urlencoded'));
    }
}
