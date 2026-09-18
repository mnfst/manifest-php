<?php declare(strict_types=1);

namespace Mnfst\Tests\Unit;

use Mnfst\Retry;
use PHPUnit\Framework\TestCase;

final class RetryTest extends TestCase
{
    private const URL = 'https://api.test/v1/search?query=Batman&page=1&page=2';

    /** @param array<string, mixed> $healed */
    private function build(
        array $healed,
        string $method = 'GET',
        string $url = self::URL,
        array $headers = ['Accept' => ['application/json'], 'Authorization' => ['Bearer t']],
        mixed $body = null,
        bool $replayable = true,
        string $contentType = '',
        string $status = 'patched',
    ): ?Retry {
        return Retry::build($method, $url, $headers, $body, $replayable, $contentType, [
            'status' => $status,
            'healAttemptId' => 'a1',
            'healedRequest' => $healed,
        ]);
    }

    public function testOnlyPatchedAndUnverifiedAuthoriseARetry(): void
    {
        self::assertNull($this->build(['url' => 'https://api.test/v1/search'], status: 'no_patch'));
        self::assertNotNull($this->build(['url' => 'https://api.test/v1/search'], status: 'unverified'));
        self::assertNull(Retry::build('GET', self::URL, [], null, true, '', ['status' => 'patched']));
    }

    public function testAHealedRequestThatNamesNothingIsNotRetried(): void
    {
        self::assertNull($this->build([]));
        self::assertNull($this->build(['method' => 'POST']));
    }

    public function testTheOriginalUrlIsKeptWhenTheHealDoesNotMoveIt(): void
    {
        $retry = $this->build(['headers' => ['X-Version' => '2']]);
        self::assertSame(self::URL, $retry?->url);
    }

    public function testAHealedUrlOnTheSameOriginIsApplied(): void
    {
        $retry = $this->build(['url' => 'https://api.test/v1/search?query=Batman']);
        self::assertSame('https://api.test/v1/search?query=Batman', $retry?->url);
    }

    public function testTheDefaultPortCountsAsTheSameOrigin(): void
    {
        self::assertNotNull($this->build(['url' => 'https://api.test:443/v1/search']));
        self::assertNotNull($this->build(['url' => 'HTTPS://API.TEST/v1/search']));
        self::assertNull($this->build(['url' => 'https://api.test:8443/v1/search']));
    }

    public function testAHealedUrlOffTheOriginIsRefused(): void
    {
        self::assertNull($this->build(['url' => 'https://evil.test/v1/search']));
        self::assertNull($this->build(['url' => 'http://api.test/v1/search']), 'scheme is part of the origin');
        self::assertNull($this->build(['url' => '/v1/search?query=Batman']), 'a relative URL has no origin');
        self::assertNull($this->build(['url' => 'https://user:pw@api.test/v1/search']), 'userinfo is a credential');
        self::assertNull($this->build(['url' => '']));
        self::assertNull($this->build(['url' => 42]));
    }

    public function testMaskedQueryValuesAreRestoredFromTheOriginal(): void
    {
        $retry = $this->build(
            ['url' => 'https://api.test/v1/search?api_key=REDACTED&page=1&guest_session_id=REDACTED'],
            url: 'https://api.test/v1/search?api_key=sk_1&page=1&page=2&guest_session_id=gs%2F9',
        );
        self::assertSame('https://api.test/v1/search?api_key=sk_1&page=1&guest_session_id=gs%2F9', $retry?->url);
    }

    public function testAMaskThatCannotBeRestoredAbortsTheRetry(): void
    {
        self::assertNull($this->build(['url' => 'https://api.test/v1/search?token=REDACTED']));
    }

    public function testOriginalHeadersAreKeptMinusContentLength(): void
    {
        $retry = $this->build(
            ['url' => 'https://api.test/v1/search'],
            headers: ['Accept' => ['application/json'], 'Content-Length' => ['12'], 'X-Trace' => 'abc'],
        );
        self::assertSame(['Accept' => ['application/json'], 'X-Trace' => ['abc']], $retry?->headers);
    }

    public function testHealedHeadersSetReplaceAndRemoveCaseInsensitively(): void
    {
        $retry = $this->build(
            ['headers' => ['accept' => 'application/xml', 'X-GitHub-Api-Version' => '2022-11-28', 'authorization' => null]],
        );
        self::assertSame(
            ['accept' => ['application/xml'], 'X-GitHub-Api-Version' => ['2022-11-28']],
            $retry?->headers,
        );
    }

    public function testAMaskedHeaderValueKeepsTheOriginal(): void
    {
        $retry = $this->build(['headers' => ['Authorization' => 'REDACTED']]);
        self::assertSame(['Bearer t'], $retry?->headers['Authorization']);
    }

    public function testMalformedHealedHeadersAbortTheRetry(): void
    {
        self::assertNull($this->build(['headers' => ['X-Version' => 2]]));
        self::assertNull($this->build(['headers' => ['X-Version' => ['2']]]));
        self::assertNull($this->build(['headers' => ['a', 'b']]));
        self::assertNull($this->build(['headers' => 'nope']));
        self::assertNotNull($this->build(['headers' => null]), 'null means "no header change"');
    }

    public function testAGetNeverCarriesABody(): void
    {
        $retry = $this->build(['body' => ['limit' => 100]], body: ['limit' => 500], contentType: 'application/json');
        self::assertNull($retry?->body);
        self::assertNull($this->build(['url' => 'https://api.test/v1/search'], method: 'head')?->body);
    }

    public function testDeleteAndOptionsRetryBodylessWhenTheBodyMergesToNothing(): void
    {
        self::assertNull($this->build(['url' => 'https://api.test/v1/search'], method: 'DELETE')?->body);
        self::assertNull($this->build(['body' => null], method: 'OPTIONS')?->body);
        self::assertNotNull($this->build(['body' => null], method: 'OPTIONS'));
    }

    public function testAPostWhoseBodyMergesToNothingIsNotRetried(): void
    {
        self::assertNull($this->build(['url' => 'https://api.test/v1/search'], method: 'POST'));
        self::assertNull($this->build(['body' => null], method: 'POST', body: ['limit' => 5], contentType: 'application/json'));
    }

    public function testAHealedBodyIsMergedAndEncoded(): void
    {
        $retry = $this->build(
            ['body' => ['limit' => 100]],
            method: 'POST',
            body: ['limit' => 500, 'api_key' => 'sk_1'],
            contentType: 'application/json',
        );
        self::assertSame('{"limit":100,"api_key":"sk_1"}', $retry?->body, 'the withheld credential is restored');

        $form = $this->build(['body' => ['limit' => 100]], method: 'POST', body: ['limit' => '500'], contentType: 'application/x-www-form-urlencoded');
        self::assertSame('limit=100', $form?->body);
        self::assertNull($this->build(['body' => [1, 2]], method: 'POST', body: ['limit' => '500'], contentType: 'application/x-www-form-urlencoded'));
    }

    public function testTheOriginalBodyTravelsAgainWhenOnlyTheUrlChanged(): void
    {
        $retry = $this->build(['url' => 'https://api.test/v2/orders'], method: 'POST', body: ['limit' => 5], contentType: 'application/json');
        self::assertSame('{"limit":5}', $retry?->body);
    }

    public function testAnUnreadableBodyNeedsAReplacement(): void
    {
        self::assertNull($this->build(['url' => 'https://api.test/v1/search'], method: 'POST', body: null, replayable: false));
        $retry = $this->build(['body' => ['limit' => 100]], method: 'POST', body: null, replayable: false, contentType: 'application/json');
        self::assertSame('{"limit":100}', $retry?->body);
    }
}
