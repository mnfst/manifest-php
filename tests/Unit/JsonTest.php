<?php declare(strict_types=1);

namespace Mnfst\Tests\Unit;

use Mnfst\Bodies;
use Mnfst\Json;
use Mnfst\Merge;
use PHPUnit\Framework\TestCase;

final class JsonTest extends TestCase
{
    public function testAnEmptyObjectSurvivesTheRoundTrip(): void
    {
        $raw = '{"meta":{},"list":[],"nested":{"a":{}}}';
        [$parsed, $replayable] = Bodies::parseRequestBody($raw, 'application/json');

        self::assertTrue($replayable);
        self::assertTrue(Json::isEmptyObject($parsed['meta']));
        self::assertSame([], $parsed['list']);
        self::assertSame($raw, Bodies::encodeRequestBody($parsed, 'application/json'));
    }

    public function testFloatLiteralsAndSlashesAreKept(): void
    {
        [$parsed] = Bodies::parseRequestBody('{"amount":10.0,"rate":1.5,"title":"Amélie","path":"a/b"}', 'application/json');

        // The float keeps its fraction and the slash stays unescaped; unicode is
        // escaped, which is the same JSON value on the wire.
        self::assertSame(
            '{"amount":10.0,"rate":1.5,"title":"Am\u00e9lie","path":"a/b"}',
            Bodies::encodeRequestBody($parsed, 'application/json'),
        );
    }

    public function testObjectsWithKeysAreStillArrays(): void
    {
        self::assertSame(['a' => 1, 'b' => [1, 2]], Json::decode('{"a":1,"b":[1,2]}'));
        self::assertSame('text', Json::decode('"text"'));
        self::assertNull(Json::encode(['x' => INF]));
    }

    public function testAHealedEmptyObjectStillRestoresWithheldCredentials(): void
    {
        $merged = Merge::healedBody(['api_key' => 'sk_1', 'limit' => 5], ['limit' => 5], Json::decode('{}'));
        self::assertSame(['api_key' => 'sk_1'], $merged);

        self::assertTrue(Json::isEmptyObject(Merge::healedBody(['limit' => 5], ['limit' => 5], Json::decode('{}'))));
        self::assertSame('{}', Json::encode(Merge::healedBody(['limit' => 5], ['limit' => 5], Json::decode('{}'))));
    }
}
