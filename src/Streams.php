<?php declare(strict_types=1);

namespace Mnfst;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Reading PSR-7 bodies without leaving a mark and without loading more than
 * needed: a seekable stream is put back where it was, so the caller's own
 * getContents() still works, and nothing past the limit is copied into memory.
 */
final class Streams
{
    /**
     * Up to $limit bytes of a stream plus one to know it holds more.
     *
     * @return array{0: string, 1: bool} the bytes read and whether the stream holds more than $limit
     */
    public static function read(StreamInterface $stream, int $limit): array
    {
        $size = $stream->getSize();
        if ($size !== null && $size > $limit) {
            return ['', true];
        }
        $seekable = $stream->isSeekable();
        $position = $seekable ? $stream->tell() : 0;
        if ($seekable) {
            $stream->rewind();
        }
        try {
            $raw = '';
            while (!$stream->eof() && strlen($raw) <= $limit) {
                $chunk = $stream->read(min(65536, $limit + 1 - strlen($raw)));
                if ($chunk === '') {
                    break;
                }
                $raw .= $chunk;
            }

            return [$raw, strlen($raw) > $limit];
        } finally {
            if ($seekable) {
                $stream->seek($position);
            }
        }
    }

    /**
     * The response body, capped, and a response the caller can still read it
     * from. A non-seekable body (Guzzle `stream => true`) is consumed by
     * reading, so it is read whole and replaced by an in-memory copy when the
     * client gave us a way to build one.
     *
     * @param (callable(string): StreamInterface)|null $streamFor builds a body stream the client's way
     * @return array{0: string, 1: ResponseInterface}
     */
    public static function readResponse(ResponseInterface $response, ?callable $streamFor): array
    {
        $stream = $response->getBody();
        if ($stream->isSeekable() || $streamFor === null) {
            return [self::read($stream, Wire::RESPONSE_BODY_CAP)[0], $response];
        }
        $raw = $stream->getContents();

        return [$raw, $response->withBody($streamFor($raw))];
    }
}
