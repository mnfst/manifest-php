<?php declare(strict_types=1);

namespace Mnfst;

use Mnfst\Response\PrefixStream;
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
    public static function read(StreamInterface $stream, int $limit, bool $skipOversized = true): array
    {
        $size = $stream->getSize();
        if ($skipOversized && $size !== null && $size > $limit) {
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
     * Read a bounded prefix and preserve the body for the caller. Non-seekable
     * responses replay the prefix before reading more bytes from the transport.
     *
     * @return array{0: string, 1: ResponseInterface}
     */
    public static function readResponse(ResponseInterface $response): array
    {
        $stream = $response->getBody();
        if ($stream->isSeekable()) {
            return [self::read($stream, Wire::RESPONSE_BODY_CAP, false)[0], $response];
        }
        $raw = '';
        $error = null;
        try {
            while (!$stream->eof() && strlen($raw) <= Wire::RESPONSE_BODY_CAP) {
                $chunk = $stream->read(Wire::RESPONSE_BODY_CAP + 1 - strlen($raw));
                if ($chunk === '') {
                    break;
                }
                $raw .= $chunk;
            }
        } catch (\Throwable $error) {
            // Preserve bytes already read even if the transport fails next.
        }

        return [$raw, $response->withBody(new PrefixStream($raw, $stream, $error))];
    }
}
