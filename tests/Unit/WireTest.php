<?php declare(strict_types=1);

namespace Mnfst\Tests\Unit;

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
