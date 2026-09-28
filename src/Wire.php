<?php declare(strict_types=1);

namespace Mnfst;

/**
 * Build the /v1/heal payload. The SDK never parses error dialects — the raw
 * error body travels (capped) and the server normalises it.
 *
 * Everything about the failing request travels — URL with query, headers,
 * body — so the server has the whole context. Credential VALUES never do:
 * Masked replaces them in place by REDACTED (mnfst/http-redact), and Replay
 * restores them before a retry. Masked output is for the wire only; never feed
 * it back into a live request.
 */
final class Wire
{
    public const RESPONSE_BODY_CAP = 65536;
    public const HEADER_VALUE_CAP = 1024;

    /**
     * The URL a tracked call is reported under: scheme, host, port and path
     * only. The query, fragment and userinfo are where credentials ride, so
     * they never leave the process. Null when the URL is not http(s).
     */
    public static function trackedUrl(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($parts === false || !in_array($scheme, ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            return null;
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        // A secret in the path (a webhook URL, /bot<token>/) is masked here, before the spool file.
        return Masked::url($scheme . '://' . $parts['host'] . $port . ($parts['path'] ?? ''));
    }

    /**
     * A query string as raw `[name, value]` pairs in wire order, duplicates
     * kept, nothing decoded. A value is null for a bare `flag` segment.
     *
     * @return list<array{0: string, 1: string|null}>
     */
    public static function queryPairs(string $query): array
    {
        $pairs = [];
        foreach (explode('&', $query) as $segment) {
            if ($segment === '') {
                continue;
            }
            $split = explode('=', $segment, 2);
            $pairs[] = [$split[0], $split[1] ?? null];
        }

        return $pairs;
    }

    /** @return array{0: mixed, 1: bool} the body and whether it was truncated */
    public static function cappedResponseBody(string $raw): array
    {
        $truncated = strlen($raw) > self::RESPONSE_BODY_CAP;
        if ($truncated) {
            $raw = self::cutUtf8($raw, self::RESPONSE_BODY_CAP);
        }

        try {
            return [Json::decode($raw), $truncated];
        } catch (\Throwable) {
            return [$raw, $truncated];
        }
    }

    /**
     * The first $cap bytes, minus a multibyte character the cut would split:
     * a broken sequence makes the whole heal payload invalid UTF-8.
     */
    public static function cutUtf8(string $raw, int $cap): string
    {
        $cut = substr($raw, 0, $cap);
        $last = strlen($cut) - 1;
        $continuation = 0;
        while ($last >= 0 && (ord($cut[$last]) & 0xC0) === 0x80) {
            $last--;
            $continuation++;
        }
        if ($last < 0) {
            return $cut;
        }
        $lead = ord($cut[$last]);
        $expected = $lead >= 0xF0 ? 3 : ($lead >= 0xE0 ? 2 : ($lead >= 0xC0 ? 1 : 0));

        return $expected > $continuation ? substr($cut, 0, $last) : $cut;
    }

    /** The /v1/heal payload for a request Masked::request() prepared and a response it masked. */
    public static function healPayload(
        string $traceId,
        string $method,
        Masked $sent,
        int $statusCode,
        mixed $responseBody,
        bool $truncated,
        int $responseTimeMs,
    ): array {
        return [
            'traceId' => $traceId,
            'request' => [
                'method' => strtoupper($method),
                'url' => $sent->url,
                // an object even when empty: [] would encode as a JSON list
                'headers' => (object) $sent->headers,
                'body' => $sent->body,
            ],
            'response' => [
                'statusCode' => $statusCode,
                'body' => Masked::response($responseBody),
                'truncated' => $truncated,
            ],
            'responseTimeMs' => $responseTimeMs,
        ];
    }
}
