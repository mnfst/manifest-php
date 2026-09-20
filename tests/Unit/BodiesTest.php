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

    public function testFormKeysTravelVerbatim(): void
    {
        [$body] = Bodies::parseRequestBody('user.name=bob&a%20b=1&q=fight+club', Bodies::FORM);
        self::assertSame(['user.name' => 'bob', 'a b' => '1', 'q' => 'fight club'], $body, 'parse_str would have made user_name and a_b');
        self::assertSame('user.name=bob&a+b=1&q=fight+club', Bodies::encodeRequestBody($body, Bodies::FORM));
    }

    public function testARepeatedFormKeyBecomesAList(): void
    {
        [$body] = Bodies::parseRequestBody('tag=a&tag=b&tag=c', Bodies::FORM);
        self::assertSame(['tag' => ['a', 'b', 'c']], $body, 'parse_str kept only the last value');
        self::assertSame('tag=a&tag=b&tag=c', Bodies::encodeRequestBody($body, Bodies::FORM), 'a repeated name is repeated, so it round-trips');
    }

    public function testBracketNamesStayLiteral(): void
    {
        // A bracketed name is one field name, not a path: the server sees what
        // the caller sent, and the retry sends it back unchanged.
        [$body] = Bodies::parseRequestBody('u[name]=x&m[3]=three', Bodies::FORM);
        self::assertSame(['u[name]' => 'x', 'm[3]' => 'three'], $body);
        self::assertSame('u%5Bname%5D=x&m%5B3%5D=three', Bodies::encodeRequestBody($body, Bodies::FORM));
    }

    public function testFormValuesEncodeAsScalars(): void
    {
        $encoded = Bodies::encodeRequestBody(['on' => true, 'off' => false, 'none' => null, 'n' => 1.5], Bodies::FORM);
        self::assertSame('on=1&off=0&none=&n=1.5', $encoded, 'scalars are cast the way PHP encodes a form');
        self::assertNull(Bodies::encodeRequestBody(['nested' => ['a' => 1]], Bodies::FORM), 'a nested structure is not a form field');
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
