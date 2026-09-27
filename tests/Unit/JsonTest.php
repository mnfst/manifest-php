<?php declare(strict_types=1);

namespace Mnfst\Tests\Unit;

use Mnfst\Bodies;
use Mnfst\Json;
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

    public function testNumericObjectKeysAndSecretOnlyObjectsKeepTheirShape(): void
    {
        foreach (['{"0":"a","1":{}}', '{"nested":{"0":10.0},"list":[]}'] as $raw) {
            self::assertSame($raw, Json::encode(Json::decode($raw)));
        }
        self::assertSame('{"api_key":"REDACTED"}', Json::encode(\Mnfst\Masked::request('POST', 'https://a.test/', [], ['api_key' => 'local'])->body));
    }

    public function testObjectsWithKeysAreStillArrays(): void
    {
        self::assertSame(['a' => 1, 'b' => [1, 2]], Json::decode('{"a":1,"b":[1,2]}'));
        self::assertSame('text', Json::decode('"text"'));
        self::assertNull(Json::encode(['x' => INF]));
    }

    public function testAHealedEmptyObjectStillRestoresMaskedCredentials(): void
    {
        $result = static fn (string $healed): array => ['status' => 'unverified', 'healedRequest' => ['body' => Json::decode($healed)]];
        $plan = \Mnfst\Replay::plan('POST', 'https://a.test/', ['api_key' => 'sk_1', 'limit' => 5], true, \Mnfst\Bodies::JSON, $result('{}'));
        self::assertSame('{"api_key":"sk_1"}', $plan['body']);

        $plan = \Mnfst\Replay::plan('POST', 'https://a.test/', ['limit' => 5], true, \Mnfst\Bodies::JSON, $result('{}'));
        self::assertSame('{}', $plan['body']);
    }
}
