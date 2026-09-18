<?php declare(strict_types=1);

namespace Mnfst\Tests\Unit;

use Mnfst\Capture;
use Mnfst\HealEvent;
use Mnfst\Replay;
use PHPUnit\Framework\TestCase;

/**
 * The plain data holders: every field is stored as given and none can be
 * reassigned afterwards.
 */
final class DataHoldersTest extends TestCase
{
    public function testHealEventStoresEveryFieldImmutably(): void
    {
        $operations = [['op' => 'set', 'path' => 'limit', 'value' => 100]];
        $event = new HealEvent('https://api.test/x', 400, 'patched', 200, 12, $operations);

        self::assertSame('https://api.test/x', $event->url);
        self::assertSame(400, $event->statusCode);
        self::assertSame('patched', $event->healStatus);
        self::assertSame(200, $event->replayStatusCode);
        self::assertSame(12, $event->healMs);
        self::assertSame($operations, $event->operations);
        self::assertReadonly($event, 'statusCode', 500);
    }

    public function testHealEventAcceptsItsNullableFields(): void
    {
        $event = new HealEvent('https://api.test/x', 422, 'no_patch', null, 3, null);

        self::assertNull($event->replayStatusCode, 'null when there was no retry');
        self::assertNull($event->operations);
    }

    public function testCaptureStoresEveryFieldImmutably(): void
    {
        $capture = new Capture(
            'POST',
            'https://api.test/orders?api_key=sk_1',
            ['content-type' => ['application/json'], 'authorization' => ['Bearer t']],
            '{"limit":500}',
            false,
            400,
            '{"error":"too big"}',
            1234.5,
        );

        self::assertSame('POST', $capture->method);
        self::assertSame('https://api.test/orders?api_key=sk_1', $capture->url);
        self::assertSame(['content-type' => ['application/json'], 'authorization' => ['Bearer t']], $capture->headers);
        self::assertSame('{"limit":500}', $capture->body);
        self::assertFalse($capture->oversized);
        self::assertSame(400, $capture->status);
        self::assertSame('{"error":"too big"}', $capture->responseBody);
        self::assertSame(1234.5, $capture->started);
        self::assertReadonly($capture, 'status', 200);
    }

    public function testCaptureCarriesAnAbsentOrOversizedBody(): void
    {
        $capture = new Capture('GET', 'https://api.test/x', [], null, true, 413, '', 0.0);

        self::assertNull($capture->body, 'a body that could not be read travels as null');
        self::assertTrue($capture->oversized);
    }

    public function testReplayStoresEveryFieldImmutably(): void
    {
        $response = (object) ['handedBack' => true];
        $replay = new Replay(200, '{"ok":true}', $response);

        self::assertSame(200, $replay->status);
        self::assertSame('{"ok":true}', $replay->body);
        self::assertSame($response, $replay->response, 'the client response object is passed through unchanged');
        self::assertReadonly($replay, 'status', 500);
    }

    /** A readonly property rejects reassignment with an Error. */
    private static function assertReadonly(object $target, string $property, mixed $value): void
    {
        $threw = false;
        try {
            $target->{$property} = $value;
        } catch (\Error $e) {
            $threw = true;
            self::assertStringContainsString('readonly', $e->getMessage());
        }
        self::assertTrue($threw, "$property must be readonly");
    }
}
