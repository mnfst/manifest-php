<?php declare(strict_types=1);

namespace Mnfst\Tests\Support;

use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/** A body that counts the bytes anyone read from it. */
final class CountingStream implements StreamInterface
{
    public int $bytesRead = 0;
    private StreamInterface $inner;

    public function __construct(string $content, private readonly bool $knownSize = true)
    {
        $this->inner = Utils::streamFor($content);
    }

    public function read(int $length): string
    {
        $chunk = $this->inner->read($length);
        $this->bytesRead += strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        $rest = $this->inner->getContents();
        $this->bytesRead += strlen($rest);

        return $rest;
    }

    public function __toString(): string
    {
        $this->inner->rewind();

        return $this->getContents();
    }

    public function getSize(): ?int
    {
        return $this->knownSize ? $this->inner->getSize() : null;
    }

    public function close(): void { $this->inner->close(); }
    public function detach() { return $this->inner->detach(); }
    public function tell(): int { return $this->inner->tell(); }
    public function eof(): bool { return $this->inner->eof(); }
    public function isSeekable(): bool { return true; }
    public function seek(int $offset, int $whence = SEEK_SET): void { $this->inner->seek($offset, $whence); }
    public function rewind(): void { $this->inner->rewind(); }
    public function isWritable(): bool { return false; }
    public function write(string $string): int { throw new \RuntimeException('read-only'); }
    public function isReadable(): bool { return true; }
    public function getMetadata(?string $key = null) { return $this->inner->getMetadata($key); }
}
