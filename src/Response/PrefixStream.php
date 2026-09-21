<?php declare(strict_types=1);

namespace Mnfst\Response;

use Psr\Http\Message\StreamInterface;

/** Replays a captured prefix, then reads the rest directly from the transport. */
final class PrefixStream implements StreamInterface
{
    private int $position = 0;

    public function __construct(
        private string $prefix,
        private readonly StreamInterface $inner,
        private readonly ?\Throwable $error = null,
    ) {
    }

    public function __toString(): string
    {
        try {
            return $this->getContents();
        } catch (\Throwable) {
            return '';
        }
    }

    public function read($length): string
    {
        if ($length < 0) {
            throw new \RuntimeException('Read length must be non-negative');
        }
        if ($length === 0) {
            return '';
        }
        if ($this->prefix !== '') {
            $chunk = substr($this->prefix, 0, $length);
            $this->prefix = substr($this->prefix, strlen($chunk));
        } else {
            if ($this->error !== null) {
                throw $this->error;
            }
            $chunk = $this->inner->read($length);
        }
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        $result = '';
        while (!$this->eof()) {
            $chunk = $this->read(65536);
            if ($chunk === '') {
                break;
            }
            $result .= $chunk;
        }

        return $result;
    }

    public function getSize(): ?int { return $this->inner->getSize(); }
    public function tell(): int { return $this->position; }
    public function eof(): bool { return $this->error === null && $this->prefix === '' && $this->inner->eof(); }
    public function isSeekable(): bool { return false; }
    public function seek($offset, $whence = SEEK_SET): void { throw new \RuntimeException('Stream is not seekable'); }
    public function rewind(): void { $this->seek(0); }
    public function isWritable(): bool { return false; }
    public function write($string): int { throw new \RuntimeException('Stream is read-only'); }
    public function isReadable(): bool { return $this->prefix !== '' || $this->inner->isReadable(); }
    public function getMetadata($key = null) { return $this->inner->getMetadata($key); }

    public function close(): void
    {
        $this->prefix = '';
        $this->inner->close();
    }

    public function detach()
    {
        $this->prefix = '';

        return $this->inner->detach();
    }
}
