<?php declare(strict_types=1);

namespace Mnfst\Tests\Unit;

use Mnfst\Bodies;
use Mnfst\Masked;
use Mnfst\Replay;
use PHPUnit\Framework\TestCase;

/** A retry puts back what was masked, and never sends the mask itself. */
final class ReplayRestoreTest extends TestCase
{
    private const URL = 'https://a.test/orders';

    private static function plan(string $url, array $headers, mixed $body, array $healed): ?array
    {
        $sent = Masked::request('POST', $url, $headers, $body);
        $result = ['status' => 'unverified', 'healedRequest' => $healed];

        return Replay::plan('POST', $url, $body, true, Bodies::JSON, $result, $headers, $sent);
    }

    public function testANestedMaskedValueIsRestored(): void
    {
        $plan = self::plan(self::URL, [], ['user' => ['password' => 'hunter2'], 'limit' => 500], ['body' => ['user' => ['password' => 'REDACTED'], 'limit' => 100]]);
        self::assertSame(['user' => ['password' => 'hunter2'], 'limit' => 100], json_decode($plan['body'], true));
    }

    public function testAPartlyMaskedStringIsRestored(): void
    {
        $note = 'my key is sk_live_' . str_repeat('Ab3xQ9zL7mK2', 2) . ' ok';
        $sent = Masked::request('POST', self::URL, [], ['note' => $note, 'limit' => 500]);
        self::assertSame('my key is REDACTED ok', $sent->body['note']);
        $plan = self::plan(self::URL, [], ['note' => $note, 'limit' => 500], ['body' => ['note' => 'my key is REDACTED ok', 'limit' => 100]]);
        self::assertSame($note, json_decode($plan['body'], true)['note']);
    }

    public function testAValueTheHealChangedIsKept(): void
    {
        $plan = self::plan(self::URL, [], ['password' => 'hunter2', 'limit' => 500], ['body' => ['password' => 'new-by-op', 'limit' => 100]]);
        self::assertSame(['password' => 'new-by-op', 'limit' => 100], json_decode($plan['body'], true));
    }

    public function testAMaskWithNothingToRestoreIsNeverSent(): void
    {
        self::assertNull(self::plan(self::URL, [], ['limit' => 500], ['body' => ['limit' => 100, 'extra' => 'REDACTED']]));
    }

    public function testABodyTooDeepToEncodeIsNotSentAndTheMaskIsNeverRetried(): void
    {
        $deep = ['password' => json_decode(str_repeat('[', 100) . '"x"' . str_repeat(']', 100))];
        self::assertNull(Masked::request('POST', self::URL, [], $deep)->body);
        self::assertNull(self::plan(self::URL, [], $deep, ['body' => 'REDACTED']));
    }

    public function testAMaskedValueTheHealLeftOutIsPutBack(): void
    {
        $plan = self::plan(self::URL, [], ['user' => ['password' => 'hunter2', 'n' => 1]], ['body' => ['user' => ['n' => 2]]]);
        self::assertSame(['user' => ['n' => 2, 'password' => 'hunter2']], json_decode($plan['body'], true));
    }

    public function testAMaskedPathSegmentIsRestored(): void
    {
        $url = 'https://hooks.slack.com/services/T0ABC/B0DEF/abcdefghijkl';
        $plan = self::plan($url, [], ['text' => 'hi'], ['url' => 'https://hooks.slack.com/services/T0ABC/B0DEF/REDACTED?v=2']);
        self::assertSame('https://hooks.slack.com/services/T0ABC/B0DEF/abcdefghijkl?v=2', $plan['url']);
    }

    public function testAMaskedQueryCredentialTheHealLeftOutIsPutBack(): void
    {
        $plan = self::plan(self::URL . '?code=abc&limit=500', [], ['x' => 1], ['url' => self::URL . '?limit=100']);
        self::assertSame(self::URL . '?limit=100&code=abc', $plan['url']);
    }

    public function testAnUnchangedPartlyMaskedHeaderKeepsTheOriginal(): void
    {
        $key = 'sk_live_' . str_repeat('Ab3xQ9zL7mK2', 2);
        $headers = ['X-Note' => "key $key here", 'Accept' => 'application/json'];
        $plan = self::plan(self::URL, $headers, ['x' => 1], ['headers' => ['x-note' => 'key REDACTED here', 'accept' => 'text/plain']]);
        self::assertSame(['accept' => 'text/plain'], $plan['headers']);
    }

    public function testAChangedHeaderStillHoldingTheMaskIsRefused(): void
    {
        self::assertNull(self::plan(self::URL, ['Authorization' => 'Bearer t'], ['x' => 1], ['headers' => ['authorization' => 'Token REDACTED']]));
    }

    public function testAHealThatDropsAListItemNeverMovesASecretToAnotherItem(): void
    {
        $body = ['items' => [['id' => 1, 'api_key' => 'KEY_ONE'], ['id' => 2, 'api_key' => 'KEY_TWO']]];
        $plan = self::plan(self::URL, [], $body, ['body' => ['items' => [['id' => 2, 'api_key' => 'REDACTED']]]]);
        self::assertTrue($plan === null || !str_contains($plan['body'], 'KEY_ONE'), 'KEY_ONE must never land on item 2');
    }

    public function testAHealThatReordersAListNeverSwapsSecrets(): void
    {
        $body = [['id' => 1, 'token' => 'TK1'], ['id' => 2, 'token' => 'TK2']];
        $plan = self::plan(self::URL, [], $body, ['body' => [['id' => 2, 'token' => 'REDACTED'], ['id' => 1, 'token' => 'REDACTED']]]);
        self::assertNull($plan);
    }

    public function testARemovedMaskedListItemStaysRemoved(): void
    {
        $plan = self::plan(self::URL, [], ['password' => ['P_A', 'P_B'], 'x' => 1], ['body' => ['password' => ['REDACTED'], 'x' => 1]]);
        self::assertTrue($plan === null || !str_contains($plan['body'], 'P_B'), 'the heal removed an item: it must not come back');
    }

    public function testAHealThatShiftsPathSegmentsNeverMovesASecret(): void
    {
        $url = 'https://hooks.x.test/a/' . self::rand() . '/b/' . self::rand() . '/c';
        $sent = Masked::request('POST', $url, [], ['x' => 1]);
        $plan = self::plan($url, [], ['x' => 1], ['url' => 'https://hooks.x.test/b/REDACTED/c']);
        self::assertNull($plan, 'masked segments moved: which secret goes where is unknown (' . $sent->url . ')');
    }

    public function testAnOriginalMentioningTheMaskDoesNotLetANewMaskThrough(): void
    {
        $plan = self::plan(self::URL, [], ['note' => 'REDACTED', 'api_key' => 'SECRET'], ['body' => ['note' => 'REDACTED', 'apiKey' => 'REDACTED']]);
        self::assertNull($plan);
    }

    public function testAQueryValueHoldingTheMaskIsNeverSent(): void
    {
        self::assertNull(self::plan(self::URL . '?key=abc', [], ['x' => 1], ['url' => self::URL . '?key=xREDACTEDx']));
    }

    public function testADuplicateMaskedQueryNameTheHealThinnedIsRefused(): void
    {
        self::assertNull(self::plan(self::URL . '?key=K1&key=K2', [], ['x' => 1], ['url' => self::URL . '?key=REDACTED']));
    }

    public function testUserinfoWithAnAtSignIsStrippedWhole(): void
    {
        self::assertSame('https://api.x.test/x', Masked::url('https://user:p@ss@api.x.test/x'));
    }

    private static function rand(): string
    {
        return 'Zq8vK2mL9xB4nR7tW1yC5dF3';
    }
}
