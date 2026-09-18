<?php declare(strict_types=1);

namespace Mnfst;

/**
 * Capture any method, but only request-side failures.
 *
 * A 4xx is the server saying the request was at fault. Four are forbidden,
 * along with every 5xx: 401/403 (auth), 402 (billing), 429 (rate limits) and
 * server faults cannot be fixed by editing the request.
 *
 * That status gate is the client's only eligibility rule. Whether a captured
 * failure gets retried is the server's call.
 */
final class Gate
{
    public const FORBIDDEN_STATUSES = [401, 402, 403, 429];

    /** Past this a body is a payload, not a form to repair. */
    public const REQUEST_BODY_LIMIT = 262144;

    public static function shouldCapture(int $status): bool
    {
        return $status >= 400 && $status < 500
            && !in_array($status, self::FORBIDDEN_STATUSES, true);
    }

    /**
     * The request body as JSON — object, array or scalar — else null (absent,
     * huge, not JSON). Fails open on anything.
     */
    public static function parseJsonBody(?string $body): mixed
    {
        if ($body === null || $body === '' || strlen($body) > self::REQUEST_BODY_LIMIT) {
            return null;
        }

        try {
            return Json::decode($body);
        } catch (\Throwable) {
            return null;
        }
    }
}
