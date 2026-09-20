<?php declare(strict_types=1);

namespace Mnfst\Tests\Integration;

use Mnfst\Config;
use Mnfst\HealApi;
use Mnfst\Hooks\Curl;
use Mnfst\Tests\Support\StubManifest;
use Mnfst\Tests\Support\StubUpstream;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
final class CurlHookTest extends TestCase
{
    private StubManifest $manifest;
    private StubUpstream $upstream;

    protected function setUp(): void
    {
        $this->manifest = new StubManifest();
        $this->manifest->start();
        $this->upstream = new StubUpstream();
        $this->upstream->start();

        $config = Config::resolve('k', $this->manifest->url);
        Curl::install($config, new HealApi($config));
    }

    protected function tearDown(): void
    {
        $this->manifest->stop();
        $this->upstream->stop();
    }

    /** @return array{0: int, 1: string} */
    private function post(string $path, array $body): array
    {
        $ch = curl_init($this->upstream->url . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return [$status, $raw];
    }

    public function testAFailureIsCaptured(): void
    {
        $this->post('/orders', ['limit' => 500]);

        $heals = $this->manifest->heals();
        self::assertCount(1, $heals);
        self::assertSame(400, $heals[0]['response']['statusCode']);
        self::assertStringContainsString('/orders', $heals[0]['request']['url']);
    }

    public function testHeadersTravelAsAJsonMap(): void
    {
        $this->post('/orders', ['limit' => 500]);

        [$raw] = $this->manifest->rawHeals();
        self::assertStringContainsString(
            '"headers":{"content-type":"application\/json"}',
            $raw,
            'the headers the call set travel, as a map: the server rejects a JSON list where it expects one',
        );
    }

    public function testHeadersTravelAsAnEmptyObjectWhenTheCallSetNone(): void
    {
        $ch = curl_init($this->upstream->url . '/orders');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['limit' => 500]),
        ]);
        curl_exec($ch);

        [$raw] = $this->manifest->rawHeals();
        self::assertStringContainsString('"headers":{}', $raw, 'never [] — the server expects a map');
    }

    public function testTheRequestAndResponseBodiesTravel(): void
    {
        $this->post('/orders', ['limit' => 500]);

        [$heal] = $this->manifest->heals();
        self::assertSame(['limit' => 500], $heal['request']['body']);
        self::assertStringContainsString('too big', $heal['response']['body']['error']);
    }

    public function testTheApplicationStillReceivesTheOriginalError(): void
    {
        [$status, $raw] = $this->post('/orders', ['limit' => 500]);

        self::assertSame(400, $status, 'raw curl can never be healed');
        self::assertStringContainsString('too big', $raw);
    }

    public function testAnAttemptTheServerOpensIsClosedAsNotAttempted(): void
    {
        // The payload does not say the capture came from raw curl, so the
        // server may open an attempt like for any other capture. Nothing is
        // replayed; the attempt must not wait forever for an answer.
        $this->manifest->setResult([
            'status' => 'patched',
            'healAttemptId' => 'a1',
            'healedRequest' => ['body' => ['limit' => 100]],
        ]);
        [$status] = $this->post('/orders', ['limit' => 500]);

        self::assertSame(400, $status, 'raw curl is never healed');
        self::assertSame(
            [['a1', ['failure' => ['kind' => 'not_attempted', 'message' => 'replay_not_attempted']]]],
            $this->manifest->outcomes(),
        );
    }

    public function testTheRequestHeadersTravelMasked(): void
    {
        $ch = curl_init($this->upstream->url . '/orders');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['limit' => 500]),
        ]);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer app-secret', 'X-Trace: t1']);
        curl_exec($ch);

        $sent = $this->manifest->heals()[0]['request']['headers'];
        self::assertSame('application/json', $sent['content-type']);
        self::assertSame('REDACTED', $sent['authorization']);
        self::assertSame('t1', $sent['x-trace']);
    }

    public function testASuccessIsNotCaptured(): void
    {
        $this->post('/orders', ['limit' => 5]);
        self::assertSame([], $this->manifest->heals());
    }

    public function testTheSdkOwnCallsAreNotCaptured(): void
    {
        $this->post('/orders', ['limit' => 500]);
        // the SDK's own POST /v1/heal is itself a curl_exec; it must not recurse
        self::assertCount(1, $this->manifest->heals());
    }

    public function testWordPressTrafficIsLeftToTheRequestsHook(): void
    {
        // Requests' curl transport uses curl_exec; the curl hook must defer to
        // the WordPress hook so a WP call is captured once, not twice.
        \Mnfst\Hooks\WordPress::install(
            \Mnfst\Config::resolve('k', $this->manifest->url),
            new \Mnfst\HealApi(\Mnfst\Config::resolve('k', $this->manifest->url)),
        );
        $this->manifest->setResult(['status' => 'no_patch']);

        \WpOrg\Requests\Requests::post($this->upstream->url . '/orders', ['Content-Type' => 'application/json'], json_encode(['limit' => 500]));

        self::assertCount(1, $this->manifest->heals(), 'the WordPress hook captures it; the curl hook defers');
    }

    public function testGuzzleTrafficIsLeftToTheGuzzleHook(): void
    {
        // Guzzle runs on curl underneath. With both hooks installed the failure
        // must be reported once, by the hook that can actually heal it.
        \Mnfst\Hooks\Guzzle::install(
            Config::resolve('k', $this->manifest->url),
            new HealApi(Config::resolve('k', $this->manifest->url)),
        );

        (new \GuzzleHttp\Client(['http_errors' => false]))
            ->post($this->upstream->url . '/orders', ['json' => ['limit' => 500]]);

        self::assertCount(1, $this->manifest->heals(), 'exactly one report, not one per layer');
    }
}
