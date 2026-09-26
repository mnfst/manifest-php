<?php declare(strict_types=1);

namespace Mnfst;

/**
 * @param list<string>|string|null $allowlist only these calls reach Manifest: domains or domain/paths (default MNFST_ALLOWLIST)
 * @param list<string>|string|null $denylist these calls never reach Manifest, same entries (default MNFST_DENYLIST)
 */
function manifest(
    ?string $apiKey = null,
    ?string $url = null,
    ?callable $onHeal = null,
    array|string|null $allowlist = null,
    array|string|null $denylist = null,
): void {
    Manifest::start($apiKey, $url, $onHeal, $allowlist, $denylist);
}
