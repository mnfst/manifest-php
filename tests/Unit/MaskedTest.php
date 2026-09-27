<?php declare(strict_types=1);

namespace Mnfst\Tests\Unit;

use Mnfst\Bodies;
use Mnfst\Masked;
use Mnfst\Wire;
use PHPUnit\Framework\TestCase;

/** What leaves the machine, masked by mnfst/http-redact. */
final class MaskedTest extends TestCase
{
    private static function key(): string
    {
        return 'sk_live_' . str_repeat('Ab3xQ9zL7mK2', 2); // built at run time: no key-shaped literal in the repo
    }

    public function testTheUrlLosesQueryCredentialsWebhookSecretsAndUserinfo(): void
    {
        $sent = Masked::request('GET', 'https://u:pw@hooks.slack.com/services/T0ABC/B0DEF/abcdefghijkl?page=2&code=xyz', [], null);
        self::assertSame('https://hooks.slack.com/services/T0ABC/B0DEF/REDACTED?page=2&code=REDACTED', $sent->url);
        self::assertContains(['in' => 'path', 'at' => '4'], $sent->masks);
        self::assertContains(['in' => 'query', 'at' => 'code'], $sent->masks);
    }

    public function testHeadersAreLowercasedMaskedAndCapped(): void
    {
        $sent = Masked::request('GET', 'https://a.test/', [
            'Authorization' => 'Bearer abc.def',
            'Accept' => ['application/json', 'text/plain'],
            'X-Long' => str_repeat('a', 2000),
        ], null);
        self::assertSame('Bearer REDACTED', $sent->headers['authorization']);
        self::assertSame('application/json, text/plain', $sent->headers['accept']);
        self::assertSame(Wire::HEADER_VALUE_CAP, strlen($sent->headers['x-long']));
    }

    public function testCookiesNeverTravel(): void
    {
        $sent = Masked::request('GET', 'https://a.test/', ['Cookie' => 'session=abc; theme=dark', 'set-cookie' => 'x=1', 'Accept' => 'text/html'], null);
        self::assertSame(['accept' => 'text/html'], $sent->headers);
        self::assertSame([], $sent->masks);
    }

    public function testANestedCredentialIsMaskedInPlaceAtItsPointer(): void
    {
        $sent = Masked::request('POST', 'https://a.test/', [], ['user' => ['name' => 'Ada', 'password' => 'hunter2'], 'limit' => 5]);
        self::assertSame(['user' => ['name' => 'Ada', 'password' => 'REDACTED'], 'limit' => 5], $sent->body);
        self::assertContains(['in' => 'body', 'at' => '/user/password'], $sent->masks);
    }

    public function testAKeyUnderAHarmlessNameIsMaskedByShape(): void
    {
        $sent = Masked::request('POST', 'https://a.test/', [], ['settings' => ['value' => self::key()]]);
        self::assertSame(['settings' => ['value' => 'REDACTED']], $sent->body);
    }

    public function testAFormBodyKeepsItsFieldNames(): void
    {
        $form = Bodies::parseForm('grant_type=authorization_code&code=abc&code_verifier=def&user[password]=p');
        $sent = Masked::request('POST', 'https://a.test/token', [], $form);
        self::assertSame(['grant_type' => 'authorization_code', 'code' => 'REDACTED', 'code_verifier' => 'REDACTED', 'user[password]' => 'REDACTED'], (array) $sent->body);
    }

    public function testNoBodyStaysNoBody(): void
    {
        self::assertNull(Masked::request('GET', 'https://a.test/', [], null)->body);
    }

    public function testAResponseThatEchoesAKeyIsMasked(): void
    {
        self::assertSame('Bad key: REDACTED.', Masked::response('Bad key: ' . self::key() . '.'));
        self::assertSame(['error' => ['message' => 'Bad key: REDACTED.']], Masked::response(['error' => ['message' => 'Bad key: ' . self::key() . '.']]));
    }

    public function testAUrlForMessagesIsMaskedWithoutUserinfo(): void
    {
        self::assertSame('https://a.test/x?token=REDACTED', Masked::url('https://u:p@a.test/x?token=abc'));
    }

    public function testATrackedUrlDropsTheQueryAndMasksPathSecrets(): void
    {
        self::assertSame('https://hooks.slack.com/services/T0ABC/B0DEF/REDACTED', Wire::trackedUrl('https://hooks.slack.com/services/T0ABC/B0DEF/abcdefghijkl?x=1'));
        self::assertSame('https://api.test/v1/users/42', Wire::trackedUrl('https://api.test/v1/users/42?page=2'));
    }
}
