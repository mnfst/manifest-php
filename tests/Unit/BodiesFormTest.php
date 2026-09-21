<?php declare(strict_types=1);

namespace Mnfst\Tests\Unit;

use Mnfst\Bodies;
use PHPUnit\Framework\TestCase;

/**
 * Form and JSON parse/encode gaps beyond BodiesTest: deeper nesting, sparse
 * indices, empty and bare fields, the structural depth limit, and which
 * content types route to the JSON path.
 */
final class BodiesFormTest extends TestCase
{
    /** @return array{0: mixed, 1: string|null} */
    private static function roundTrip(string $raw): array
    {
        [$parsed] = Bodies::parseRequestBody($raw, Bodies::FORM);

        return [$parsed, Bodies::encodeRequestBody($parsed, Bodies::FORM)];
    }

    public function testNumericFormNamesAndEmptyHealedMapsRemainForms(): void
    {
        [$parsed, $encoded] = self::roundTrip('0=first&1=second');
        self::assertSame('{"0":"first","1":"second"}', \Mnfst\Json::encode($parsed));
        self::assertSame('0=first&1=second', $encoded);
        self::assertSame('', Bodies::encodeRequestBody(new \stdClass(), Bodies::FORM));
        self::assertSame([null, false], Bodies::parseRequestBody('a='.str_repeat('x', \Mnfst\Gate::REQUEST_BODY_LIMIT), Bodies::FORM));
    }

    public function testAnEmptyFormValueRoundTrips(): void
    {
        [$parsed, $encoded] = self::roundTrip('a=');
        self::assertSame(['a' => ''], $parsed);
        self::assertSame('a=', $encoded);
    }

    public function testABareFormKeyParsesAsAnEmptyStringValue(): void
    {
        [$parsed, $encoded] = self::roundTrip('flag');
        self::assertSame(['flag' => ''], $parsed);
        self::assertSame('flag=', $encoded, 'it re-encodes with the explicit empty value');
    }

    public function testALiteralPlusIsDistinctFromAnEncodedSpace(): void
    {
        [$parsed, $encoded] = self::roundTrip('a=%2B&b=x+y');
        self::assertSame(['a' => '+', 'b' => 'x y'], $parsed);
        self::assertSame('a=%2B&b=x+y', $encoded);
    }

    public function testAVendorJsonContentTypeUsesTheJsonPath(): void
    {
        [$parsed, $replayable] = Bodies::parseRequestBody('{"a":1}', 'application/vnd.api+json');
        self::assertSame(['a' => 1], $parsed);
        self::assertTrue($replayable);
    }

    public function testAnEmptyContentTypeFallsBackToJson(): void
    {
        [$parsed, $replayable] = Bodies::parseRequestBody('{"a":1}', '');
        self::assertSame(['a' => 1], $parsed);
        self::assertTrue($replayable);
    }

    public function testInvalidJsonUnderAJsonContentTypeIsReportedButNotReplayable(): void
    {
        [$parsed, $replayable] = Bodies::parseRequestBody('not json', 'application/json');
        self::assertNull($parsed);
        self::assertFalse($replayable);
    }

    public function testAJsonListBodyRoundTrips(): void
    {
        [$parsed, $replayable] = Bodies::parseRequestBody('[1,2,3]', 'application/json');
        self::assertSame([1, 2, 3], $parsed);
        self::assertTrue($replayable);
        self::assertSame('[1,2,3]', Bodies::encodeRequestBody($parsed, 'application/json'));
    }
}
