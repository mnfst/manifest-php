<?php declare(strict_types=1);

namespace Mnfst;

/**
 * One failing call as plain data: what every client hook hands to the Healer,
 * whatever the client's own request and response objects look like.
 */
final class Capture
{
    /**
     * @param string $method the method as sent
     * @param string $url the URL as sent, credentials included
     * @param array<string, list<string>> $headers the request headers as sent, original names
     * @param string|null $body the request body, null when there was none or it could not be read
     * @param bool $oversized true when the body exceeds the limit or cannot be read safely; never retried
     * @param int $status the status the upstream answered with
     * @param string $responseBody the response body, at most the cap plus one byte
     * @param float $started microtime(true) when the request went out
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body,
        public readonly bool $oversized,
        public readonly int $status,
        public readonly string $responseBody,
        public readonly float $started,
    ) {
    }
}
