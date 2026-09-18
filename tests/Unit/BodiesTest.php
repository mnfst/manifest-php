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
        self::assertSame('tag%5B0%5D=a&tag%5B1%5D=b&tag%5B2%5D=c', Bodies::encodeRequestBody($body, Bodies::FORM));
    }

    public function testBracketPathsNest(): void
    {
        [$body] = Bodies::parseRequestBody('u[name]=x&u[roles][]=r1&u[roles][]=r2&m[3]=three', Bodies::FORM);
        self::assertSame(['u' => ['name' => 'x', 'roles' => ['r1', 'r2']], 'm' => [3 => 'three']], $body);
        self::assertSame(
            'u%5Bname%5D=x&u%5Broles%5D%5B0%5D=r1&u%5Broles%5D%5B1%5D=r2&m%5B3%5D=three',
            Bodies::encodeRequestBody($body, Bodies::FORM),
        );
    }

    public function testAMalformedFormIsReportedButNotReplayable(): void
    {
        foreach (['a[]=1&a[k]=2', 'a[k]=1&a[]=2', '[x]=1', 'a[b=1', 'a]=1', 'x=%E9', 'a=1&a[b]=2'] as $raw) {
            [$body, $replayable] = Bodies::parseRequestBody($raw, Bodies::FORM);
            self::assertNull($body, $raw);
            self::assertFalse($replayable, $raw);
        }
    }

    public function testFormValuesEncodeLikeTheNodeSdk(): void
    {
        $encoded = Bodies::encodeRequestBody(['on' => true, 'off' => false, 'none' => null, 'n' => 1.5, 'empty' => new \stdClass()], Bodies::FORM);
        self::assertSame('on=true&off=false&none=&n=1.5', $encoded);
        self::assertNull(Bodies::encodeRequestBody(['x' => INF], Bodies::FORM));
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
