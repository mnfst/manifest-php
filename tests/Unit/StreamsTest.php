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

        [$body, $out] = Streams::readResponse($response);

        self::assertSame('small body', $body);
        self::assertSame($response, $out, 'a seekable body is handed back untouched');
        self::assertSame('small body', (string) $out->getBody(), 'the caller can still read it');
    }

    public function testReadResponsePreservesANonSeekableBody(): void
    {
        $response = new Response(400, [], new NoSeekStream(Utils::streamFor('stream body')));

        [$body, $out] = Streams::readResponse($response);

        self::assertSame('stream body', $body);
        self::assertNotSame($response, $out, 'a fresh response carries the rebuilt body');
        self::assertSame('stream body', (string) $out->getBody());
    }

    public function testReadResponseKeepsAPartialReadReadable(): void
    {
        $response = new Response(400, [], new NoSeekStream(Utils::streamFor('fallback body')));

        [$body, $out] = Streams::readResponse($response);

        self::assertSame('fallback body', $body);
        self::assertSame('fall', $out->getBody()->read(4));
        self::assertSame('back body', $out->getBody()->getContents());
    }

    public function testReadResponseBoundsANonSeekableBodyAndPreservesTheRemainder(): void
    {
        $oversized = str_repeat('a', Wire::RESPONSE_BODY_CAP + 1000);
        $stream = new CountingStream($oversized);
        $response = new Response(400, [], new NoSeekStream($stream));

        [$body, $out] = Streams::readResponse($response);

        self::assertSame(Wire::RESPONSE_BODY_CAP + 1, strlen($body));
        self::assertSame(Wire::RESPONSE_BODY_CAP + 1, $stream->bytesRead);
        self::assertSame(hash('sha256', $oversized), hash('sha256', (string) $out->getBody()));
    }

    public function testReadResponseBoundsAnOversizedSeekableBody(): void
    {
        $response = new Response(400, [], Utils::streamFor(str_repeat('a', Wire::RESPONSE_BODY_CAP + 1000)));

        [$body, $out] = Streams::readResponse($response);

        self::assertSame(Wire::RESPONSE_BODY_CAP + 1, strlen($body));
        self::assertTrue(Wire::cappedResponseBody($body)[1], 'the prefix reports that it was truncated');
        self::assertSame($response, $out);
    }

    public function testACaptureReadErrorIsPreservedAfterThePrefix(): void
    {
        $stream = new class(Utils::streamFor('')) implements \Psr\Http\Message\StreamInterface {
            use \GuzzleHttp\Psr7\StreamDecoratorTrait;
            private \Psr\Http\Message\StreamInterface $stream;
            private bool $first = true;

            public function isSeekable(): bool { return false; }
            public function eof(): bool { return false; }
            public function read(int $length): string
            {
                if (!$this->first) {
                    throw new \RuntimeException('transport failed');
                }
                $this->first = false;

                return 'prefix';
            }
        };
        [$body, $response] = Streams::readResponse(new Response(400, [], $stream));
        self::assertSame('prefix', $body);
        self::assertSame('prefix', $response->getBody()->read(6));
        $this->expectExceptionMessage('transport failed');
        $response->getBody()->getContents();
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
