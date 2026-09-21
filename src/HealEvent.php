<?php declare(strict_types=1);

namespace Mnfst;

/**
 * What the onHeal callback receives after each captured failure, whether or
 * not a retry happened. The same fields as the Node and Python SDKs' events.
 */
final class HealEvent
{
    /**
     * @param string $url the failing request's URL, credentials masked
     * @param int $statusCode the status that was captured
     * @param string $healStatus patched, unverified, no_patch, heal_unreachable or replay_failed
     * @param int|null $replayStatusCode what the retry answered, null when there was none
     * @param int $healMs time spent healing, retry included
     * @param array<int, mixed>|null $operations the operations the server applied, if any
     */
    public function __construct(
        public readonly string $url,
        public readonly int $statusCode,
        public readonly string $healStatus,
        public readonly ?int $replayStatusCode,
        public readonly int $healMs,
        public readonly ?array $operations,
    ) {
    }
}
