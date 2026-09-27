<?php declare(strict_types=1);

namespace Mnfst\Tests\Unit;

use Mnfst\Masked;
use Mnfst\Wire;
use PHPUnit\Framework\TestCase;

/**
 * Boundary and edge coverage for Wire that WireTest does not already exercise:
 * cutUtf8 at awkward cap offsets, queryPairs corner cases, and the safeUrl /
 * safeHeaders / travelingBody masking rules.
 */
final class WireMaskingTest extends TestCase
{
    public function testCutUtf8KeepsACompleteTwoByteCharacterButDropsASplitOne(): void
    {
        // é is C3 A9. Cap 3 includes it whole; cap 2 splits it after the lead byte.
        self::assertSame("a\xC3\xA9", Wire::cutUtf8("a\xC3\xA9b", 3));
        self::assertSame('a', Wire::cutUtf8("a\xC3\xA9b", 2), 'a 2-byte sequence cut after 1 byte is dropped');
    }

    public function testCutUtf8KeepsAsciiThatFollowsACompleteMultibyteCharacter(): void
    {
        // euro (3 bytes) + "ab" == 5 bytes, the cut lands on a plain ASCII byte.
        self::assertSame("\xE2\x82\xACab", Wire::cutUtf8("\xE2\x82\xACabcd", 5));
    }

    public function testCutUtf8ReturnsTheWholeStringWhenTheCapExceedsItsLength(): void
    {
        self::assertSame('abc', Wire::cutUtf8('abc', 100));
        self::assertSame("\xC3\xA9", Wire::cutUtf8("\xC3\xA9", 100), 'a complete trailing character is never dropped');
    }

    public function testCutUtf8AtAZeroCapOrEmptyInputReturnsEmpty(): void
    {
        self::assertSame('', Wire::cutUtf8('abc', 0));
        self::assertSame('', Wire::cutUtf8('', 5));
    }

    public function testCutUtf8LeavesOrphanContinuationBytesWhenNoLeadPrecedesThem(): void
    {
        // Nothing but continuation bytes: the scan runs off the start and returns the cut as-is.
        self::assertSame("\x80\x80", Wire::cutUtf8("\x80\x80x", 2));
    }

    public function testQueryPairsOnAnEmptyStringIsEmpty(): void
    {
        self::assertSame([], Wire::queryPairs(''));
    }

    public function testQueryPairsDecodesNothingAndKeepsEmptyNames(): void
    {
        self::assertSame(
            [['a%20b', 'c%2Fd'], ['x', '%E9'], ['', '']],
            Wire::queryPairs('a%20b=c%2Fd&x=%E9&='),
            'pairs travel exactly as written, undecoded',
        );
    }

    public function testTheUrlKeepsThePortAndPathWhenThereIsNoQuery(): void
    {
        self::assertSame('https://api.test:8443/a/b', Masked::url('https://api.test:8443/a/b'));
        self::assertSame('https://api.test/path', Masked::url('https://api.test/path'));
    }

    public function testAUrlParseUrlRejectsStillTravelsMasked(): void
    {
        // parse_url() fails on a non-numeric port; the masker does not need it to.
        self::assertSame('https://host:notaport/x?token=REDACTED', Masked::url('https://host:notaport/x?token=abc'));
    }

    public function testTheUrlChecksTheDecodedNameButEmitsItVerbatim(): void
    {
        // api%5Fkey decodes to api_key, so the value is masked, yet the name travels as written.
        self::assertSame('https://api.test/x?api%5Fkey=REDACTED&page=1', Masked::url('https://api.test/x?api%5Fkey=secret&page=1'));
    }

    public function testTheUrlMasksSuffixCredentialNamesInTheQuery(): void
    {
        self::assertSame('https://api.test/x?guest_session_id=REDACTED&page=1', Masked::url('https://api.test/x?guest_session_id=gs%2F9&page=1'));
    }

    public function testHeadersJoinArrayValuesWithCommaSpace(): void
    {
        self::assertSame('application/json, text/html', Masked::request('GET', 'https://a.test/', ['Accept' => ['application/json', 'text/html']], null)->headers['accept']);
    }

    public function testCookieValuesAreMaskedEvenWhenArrayValued(): void
    {
        $out = Masked::request('GET', 'https://a.test/', ['Set-Cookie' => ['a=1', 'b=2']], null)->headers;
        self::assertSame('a=REDACTED, b=REDACTED', $out['set-cookie'], 'names stay, every value goes');
    }

    public function testHeadersCapLongValues(): void
    {
        self::assertSame(Wire::HEADER_VALUE_CAP, strlen(Masked::request('GET', 'https://a.test/', ['X-Long' => str_repeat('a', 2000)], null)->headers['x-long']));
    }

    public function testNestedCredentialsAreMaskedToo(): void
    {
        self::assertSame(
            ['user' => ['api_key' => 'REDACTED'], 'api_key' => 'REDACTED', 'limit' => 5],
            Masked::request('POST', 'https://a.test/', [], ['user' => ['api_key' => 'nested'], 'api_key' => 'top', 'limit' => 5])->body,
        );
    }

    public function testSuffixNamedBodyCredentialsAreMasked(): void
    {
        self::assertSame(
            ['guest_session_id' => 'REDACTED', 'stripe_api_key' => 'REDACTED', 'limit' => 5],
            Masked::request('POST', 'https://a.test/', [], ['guest_session_id' => 'g', 'stripe_api_key' => 'k', 'limit' => 5])->body,
        );
    }

    public function testHealPayloadMasksHeadersAndPropagatesTruncation(): void
    {
        $sent = Masked::request('get', 'https://api.test/x', ['Authorization' => 'Bearer secret', 'Accept' => 'application/json'], null);
        $payload = Wire::healPayload('t2', 'get', $sent, 413, 'raw error text', true, 42);
        // headers travel as a JSON object, so an empty set is {} and not []
        $headers = (array) $payload['request']['headers'];
        self::assertSame('Bearer REDACTED', $headers['authorization']);
        self::assertSame('application/json', $headers['accept']);
        self::assertSame('raw error text', $payload['response']['body'], 'a string response body travels as-is');
        self::assertTrue($payload['response']['truncated']);
        self::assertSame(42, $payload['responseTimeMs']);
    }
}
