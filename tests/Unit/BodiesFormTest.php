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

    public function testDeeplyNestedBracketsRoundTrip(): void
    {
        [$parsed, $encoded] = self::roundTrip('a[b][c][d]=1');
        self::assertSame(['a' => ['b' => ['c' => ['d' => '1']]]], $parsed);
        self::assertSame('a%5Bb%5D%5Bc%5D%5Bd%5D=1', $encoded);
    }

    public function testSparseNumericIndicesArePreserved(): void
    {
        [$parsed, $encoded] = self::roundTrip('m[0]=a&m[2]=c');
        self::assertSame(['m' => [0 => 'a', 2 => 'c']], $parsed, 'the gap at index 1 is kept, not collapsed');
        self::assertSame('m%5B0%5D=a&m%5B2%5D=c', $encoded);
    }

    public function testNestedListsOfObjectsRoundTrip(): void
    {
        [$parsed, $encoded] = self::roundTrip('items[0][id]=1&items[1][id]=2');
        self::assertSame(['items' => [['id' => '1'], ['id' => '2']]], $parsed);
        self::assertSame('items%5B0%5D%5Bid%5D=1&items%5B1%5D%5Bid%5D=2', $encoded);
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

    public function testRawNonUtf8BytesAreRejectedByTheTopLevelGuard(): void
    {
        // A raw 0xE9 byte (not percent-encoded) fails the whole-string UTF-8 check.
        [$parsed, $replayable] = Bodies::parseRequestBody("\xE9=1", Bodies::FORM);
        self::assertNull($parsed);
        self::assertFalse($replayable);
    }

    public function testTheStructuralDepthLimitIsEnforced(): void
    {
        $atLimit = 'a' . str_repeat('[x]', 63);   // path of 64 parts
        $overLimit = 'a' . str_repeat('[x]', 64); // path of 65 parts

        [$parsedAtLimit, $replayableAtLimit] = Bodies::parseRequestBody($atLimit . '=1', Bodies::FORM);
        self::assertNotNull($parsedAtLimit);
        self::assertTrue($replayableAtLimit);

        [$parsedOver, $replayableOver] = Bodies::parseRequestBody($overLimit . '=1', Bodies::FORM);
        self::assertNull($parsedOver, 'a path past the depth limit is not replayable');
        self::assertFalse($replayableOver);
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
