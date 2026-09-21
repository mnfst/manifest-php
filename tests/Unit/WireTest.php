<?php declare(strict_types=1);

namespace Mnfst\Tests\Unit;

use Mnfst\Bodies;
use Mnfst\Wire;
use PHPUnit\Framework\TestCase;

final class WireTest extends TestCase
{
    public function testMasksSecretQueryValuesButKeepsNames(): void
    {
        $out = Wire::safeUrl('https://api.test/v1/x?api_key=sk_live_123&page=2');
        self::assertStringContainsString('api_key=REDACTED', $out);
        self::assertStringContainsString('page=2', $out);
        self::assertStringNotContainsString('sk_live_123', $out);
    }

    public function testKeepsTheQueryAsItWentOnTheWire(): void
    {
        $url = 'https://api.test/x?page=1&page=2&vote_average.gte=7&q=&flag&name=a+b%2Cc&api_key=sk_1';
        self::assertSame(
            'https://api.test/x?page=1&page=2&vote_average.gte=7&q=&flag&name=a+b%2Cc&api_key=REDACTED',
            Wire::safeUrl($url),
        );
    }

    public function testMasksNamesThatEndWithACredentialWord(): void
    {
        foreach (['guest_session_id', 'stripe_api_key', 'userPassword', 'X-Client-Secret'] as $name) {
            self::assertTrue(Wire::isSecretField($name), "$name must be secret");
        }
        foreach (['page_token', 'session_count', 'keyword', 'limit'] as $name) {
            self::assertFalse(Wire::isSecretField($name), "$name must travel");
        }
    }

    public function testSplitsAQueryIntoRawPairs(): void
    {
        self::assertSame(
            [['a', '1'], ['a', '2'], ['b', ''], ['c', null], ['d', 'x=y']],
            Wire::queryPairs('a=1&a=2&b=&c&&d=x=y'),
        );
    }

    public function testStripsUserInfoFromTheUrl(): void
    {
        self::assertSame('https://api.test/x', Wire::safeUrl('https://user:pw@api.test/x'));
    }

    public function testNormalisesSecretNames(): void
    {
        foreach (['X-Api-Key', 'apiKey', 'api_key', 'API-KEY'] as $name) {
            self::assertTrue(Wire::isSecretHeader($name), "$name must be secret");
        }
    }

    public function testMasksSecretHeadersAndLowercasesNames(): void
    {
        $out = Wire::safeHeaders(['Authorization' => 'Bearer abc', 'Accept' => 'application/json']);
        self::assertSame('REDACTED', $out['authorization']);
        self::assertSame('application/json', $out['accept']);
    }

    public function testWithholdsSecretTopLevelBodyKeys(): void
    {
        self::assertSame(['limit' => 500], Wire::travelingBody(['limit' => 500, 'api_key' => 'sk_live_1']));
    }

    public function testNonObjectBodyTravelsUnchanged(): void
    {
        self::assertSame([1, 2, 3], Wire::travelingBody([1, 2, 3]));
        self::assertSame('plain', Wire::travelingBody('plain'));
    }

    public function testCapsAndParsesTheResponseBody(): void
    {
        [$body, $truncated] = Wire::cappedResponseBody('{"error":"nope"}');
        self::assertSame(['error' => 'nope'], $body);
        self::assertFalse($truncated);

        [$text, $wasTruncated] = Wire::cappedResponseBody(str_repeat('a', 70000));
        self::assertTrue($wasTruncated);
        self::assertSame(65536, strlen($text));
    }

    public function testTheCapNeverSplitsAMultibyteCharacter(): void
    {
        // 7-byte prefix + 2-byte characters: a plain byte cut would end mid-character.
        $raw = '{"ee":"' . str_repeat("\xC3\xA9", 40000) . '"}';
        [$body, $truncated] = Wire::cappedResponseBody($raw);

        self::assertTrue($truncated);
        self::assertTrue(mb_check_encoding($body, 'UTF-8'));
        self::assertSame(65535, strlen($body));

        self::assertSame("ab\xE2\x82\xAC", Wire::cutUtf8("ab\xE2\x82\xACcd", 5));
        self::assertSame('ab', Wire::cutUtf8("ab\xE2\x82\xACcd", 4), 'a 3-byte sequence cut after 2 bytes is dropped');
        self::assertSame("\xF0\x9F\x98\x80", Wire::cutUtf8("\xF0\x9F\x98\x80\xF0\x9F\x98\x80", 6));
        self::assertSame('abc', Wire::cutUtf8('abcdef', 3));
    }

    public function testEmptyHeadersTravelAsAJsonObject(): void
    {
        $payload = Wire::healPayload('t', 'GET', 'https://a.test/x', [], null, 404, null, false, 1);
        self::assertStringContainsString('"headers":{}', json_encode($payload));
    }

    public function testAnEmptyObjectBodyTravelsAsAJsonObject(): void
    {
        [$body] = Bodies::parseRequestBody('{}', 'application/json');
        $payload = Wire::healPayload('t', 'POST', 'https://a.test/x', [], $body, 400, null, false, 1);
        self::assertStringContainsString('"body":{}', json_encode($payload));
    }

    public function testHealPayloadShape(): void
    {
        $payload = Wire::healPayload(
            't1',
            'post',
            'https://api.test/orders?token=abc',
            ['Content-Type' => 'application/json'],
            ['limit' => 500, 'token' => 'abc'],
            400,
            ['error' => 'too big'],
            false,
            25,
        );

        self::assertSame('t1', $payload['traceId']);
        self::assertSame('POST', $payload['request']['method']);
        self::assertStringContainsString('token=REDACTED', $payload['request']['url']);
        self::assertSame(['limit' => 500], $payload['request']['body']);
        self::assertSame(400, $payload['response']['statusCode']);
        self::assertSame(['error' => 'too big'], $payload['response']['body']);
        self::assertFalse($payload['response']['truncated']);
        self::assertSame(25, $payload['responseTimeMs']);
    }
}
