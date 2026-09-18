<?php declare(strict_types=1);

namespace Mnfst;

/**
 * What a retry produced: the status and body the Healer reports, and the
 * client's own response object for the caller.
 */
final class Replay
{
    /**
     * @param int $status the status the retry answered with
     * @param string $body the retry's body, at most the cap plus one byte
     * @param mixed $response the client's response object, handed back to the caller
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly mixed $response,
    ) {
    }
}
