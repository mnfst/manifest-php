<?php declare(strict_types=1);

namespace Mnfst\Tests\Unit;

use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Mnfst\Streams;
use Mnfst\Tests\Support\CountingStream;
use Mnfst\Wire;
use PHPUnit\Framework\TestCase;

final class StreamsTest extends TestCase
{
    public function testReadReturnsTheWholeContentWhenItFitsUnderTheLimit(): void
    {
        [$raw, $more] = Streams::read(Utils::streamFor('hello world'), 100);

        self::assertSame('hello world', $raw);
        self::assertFalse($more);
    }

    public function testReadRestoresASeekableStreamToItsOriginalPosition(): void
    {
        $stream = Utils::streamFor('hello world');
        $stream->seek(6);

        [$raw] = Streams::read($stream, 100);

        self::assertSame('hello world', $raw, 'the whole stream is read from the start');
        self::assertSame(6, $stream->tell(), 'the caller finds the cursor where it left it');
        self::assertSame('world', $stream->getContents(), "the caller's own getContents() still works");
    }

    public function testAKnownSizeOverTheLimitShortCircuitsWithoutReading(): void
    {
        $stream = new CountingStream('0123456789', true); // getSize() === 10
        $stream->seek(3);

        [$raw, $more] = Streams::read($stream, 5);

        self::assertSame('', $raw);
        self::assertTrue($more);
        self::assertSame(0, $stream->bytesRead, 'nothing past the limit is copied into memory');
        self::assertSame(3, $stream->tell(), 'the cursor is never touched');
    }

    public function testAnUnknownSizeOverTheLimitIsDetectedByReadingOneExtraByte(): void
    {
        $stream = new CountingStream('0123456789', knownSize: false); // getSize() === null

        [$raw, $more] = Streams::read($stream, 5);

        self::assertSame('012345', $raw, 'the limit plus one byte, to know more remains');
        self::assertSame(6, strlen($raw));
        self::assertTrue($more);
        self::assertSame(6, $stream->bytesRead, 'the read is bounded at the limit plus one');
        self::assertSame(0, $stream->tell(), 'a seekable stream of unknown size is still restored');
    }

    public function testAnUnknownSizeExactlyAtTheLimitCountsAsComplete(): void
    {
        [$raw, $more] = Streams::read(new CountingStream('01234', knownSize: false), 5);

        self::assertSame('01234', $raw);
        self::assertFalse($more, 'exactly at the limit is not "more than the limit"');
    }

    public function testANonSeekableStreamIsReadWithoutSeeking(): void
    {
        // NoSeekStream::seek() throws; read() must never call it for a non-seekable body.
        [$raw, $more] = Streams::read(new NoSeekStream(Utils::streamFor('abcdef')), 100);

        self::assertSame('abcdef', $raw);
        self::assertFalse($more);
    }

    public function testANonSeekableStreamOfUnknownSizeIsBounded(): void
    {
        $stream = self::pump('abcdefghij');

        [$raw, $more] = Streams::read($stream, 4);

        self::assertSame('abcde', $raw, 'the limit plus one byte');
        self::assertSame(5, strlen($raw));
        self::assertTrue($more);
    }

    public function testReadResponseLeavesASeekableBodyInPlace(): void
    {
        $response = new Response(400, [], Utils::streamFor('small body'));

        [$body, $out] = Streams::readResponse($response, static fn (string $s) => Utils::streamFor($s));

        self::assertSame('small body', $body);
        self::assertSame($response, $out, 'a seekable body is handed back untouched');
        self::assertSame('small body', (string) $out->getBody(), 'the caller can still read it');
    }

    public function testReadResponseRebuildsANonSeekableBodyViaStreamFor(): void
    {
        $seen = null;
        $response = new Response(400, [], new NoSeekStream(Utils::streamFor('stream body')));

        [$body, $out] = Streams::readResponse($response, function (string $s) use (&$seen) {
            $seen = $s;

            return Utils::streamFor($s);
        });

        self::assertSame('stream body', $body);
        self::assertSame('stream body', $seen, 'the raw bytes are handed to the callback');
        self::assertNotSame($response, $out, 'a fresh response carries the rebuilt body');
        self::assertTrue($out->getBody()->isSeekable(), 'the rebuilt body can be read again');
        self::assertSame('stream body', (string) $out->getBody());
    }

    public function testReadResponseWithoutAStreamForFallsBackToABoundedRead(): void
    {
        $response = new Response(400, [], new NoSeekStream(Utils::streamFor('fallback body')));

        [$body, $out] = Streams::readResponse($response, null);

        self::assertSame('fallback body', $body);
        self::assertSame($response, $out, 'with no way to rebuild, the original response is returned');
    }

    public function testReadResponseReadsANonSeekableBodyWholeToRebuildIt(): void
    {
        // The rebuild path is not bounded: the caller must receive the complete
        // body, so getContents() reads it all (the wire cap is applied later).
        $oversized = str_repeat('a', Wire::RESPONSE_BODY_CAP + 1000);
        $response = new Response(400, [], new NoSeekStream(Utils::streamFor($oversized)));

        [$body, $out] = Streams::readResponse($response, static fn (string $s) => Utils::streamFor($s));

        self::assertSame($oversized, $body);
        self::assertSame($oversized, (string) $out->getBody(), 'the rebuilt response carries the whole body');
    }

    public function testReadResponseBoundsAnOversizedSeekableBody(): void
    {
        // The seekable path goes through read(), which refuses to copy a body
        // whose known size is past the cap.
        $response = new Response(400, [], Utils::streamFor(str_repeat('a', Wire::RESPONSE_BODY_CAP + 1000)));

        [$body, $out] = Streams::readResponse($response, static fn (string $s) => Utils::streamFor($s));

        self::assertSame('', $body, 'nothing past the cap is read from a seekable body');
        self::assertSame($response, $out);
    }

    /** A non-seekable stream of unknown size backed by a fixed string. */
    private static function pump(string $data): PumpStream
    {
        $offset = 0;

        return new PumpStream(static function (int $length) use ($data, &$offset): string|false {
            if ($offset >= strlen($data)) {
                return false;
            }
            $chunk = substr($data, $offset, $length);
            $offset += strlen($chunk);

            return $chunk;
        });
    }
}
