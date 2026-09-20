<?php declare(strict_types=1);

namespace Mnfst;

/**
 * Build the /v1/heal payload. The SDK never parses error dialects — the raw
 * error body travels (capped) and the server normalises it.
 *
 * Everything about the failing request travels — URL with query, headers,
 * body — so the server has the whole context. Credential VALUES never do:
 * query and header values are masked to REDACTED with their names kept, and
 * credential-named top-level body keys are withheld and restored on retry by
 * Merge. safeUrl/safeHeaders output is for the wire only; never feed it back
 * into a live request.
 */
final class Wire
{
    public const RESPONSE_BODY_CAP = 65536;
    public const HEADER_VALUE_CAP = 1024;

    /** The client-side MINIMUM of credential-named fields. The server may know more. */
    private const SECRET_PARAMS = [
        'api_key', 'apikey', 'api_token', 'key', 'token', 'access_token', 'refresh_token',
        'auth', 'authorization', 'signature', 'sig', 'secret', 'client_secret',
        'password', 'session', 'session_id',
        'bearer', 'jwt', 'id_token', 'auth_token', 'pwd', 'passwd', 'private_key',
    ];

    /**
     * Header names are matched on ROOTS: any header whose normalised name
     * contains one carries a credential. Over-masking a harmless header costs
     * nothing — its presence still travels.
     */
    private const SECRET_HEADER_ROOTS = [
        'auth', 'key', 'token', 'secret', 'session', 'password',
        'passwd', 'cookie', 'signature', 'credential', 'bearer', 'jwt',
    ];

    /**
     * A name that ENDS with one of these carries a credential too: guest_session_id,
     * stripe_api_key, user_password. Suffixes only, so page_token or csrf stay visible
     * to the server that has to repair them.
     */
    private const SECRET_SUFFIXES = [
        'api_key', 'session_id', 'access_token', 'refresh_token', 'auth_token', 'id_token',
        'client_secret', 'private_key', 'secret', 'password', 'passwd',
    ];

    /** X-Api-Key, apiKey and api_key are all the same secret. */
    private static function normalize(string $name): string
    {
        $normalized = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name) ?? $name;
        $normalized = strtolower(str_replace('-', '_', $normalized));

        return str_starts_with($normalized, 'x_') ? substr($normalized, 2) : $normalized;
    }

    public static function isSecretField(mixed $name): bool
    {
        if (!is_string($name)) {
            return false;
        }
        $normalized = self::normalize($name);
        if (in_array($normalized, self::SECRET_PARAMS, true)) {
            return true;
        }
        foreach (self::SECRET_SUFFIXES as $suffix) {
            if (str_ends_with($normalized, '_' . $suffix)) {
                return true;
            }
        }

        return false;
    }

    public static function isSecretHeader(string $name): bool
    {
        $normalized = self::normalize($name);
        if (in_array($normalized, self::SECRET_PARAMS, true)) {
            return true;
        }
        foreach (self::SECRET_HEADER_ROOTS as $root) {
            if (str_contains($normalized, $root)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The URL as it went on the wire, minus credential values. The query is
     * handled pair by pair, never through parse_str(): that would collapse a
     * duplicated key (`page=1&page=2`, the very thing some APIs reject) and
     * rewrite `a.b` as `a_b`, and the server can only repair what it sees.
     */
    public static function safeUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return 'REDACTED_URL';
        }

        $query = '';
        if (isset($parts['query']) && $parts['query'] !== '') {
            $masked = [];
            foreach (self::queryPairs($parts['query']) as [$name, $value]) {
                if ($value === null) {
                    $masked[] = $name;
                    continue;
                }
                $masked[] = $name . '=' . (self::isSecretField(urldecode($name)) ? 'REDACTED' : $value);
            }
            $query = '?' . implode('&', $masked);
        }

        // user:password@host is a credential too — keep host[:port] only.
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';

        return $scheme . $host . $port . ($parts['path'] ?? '') . $query;
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

    /** Every header travels, lowercased; credential values are masked. */
    public static function safeHeaders(iterable $headers): array
    {
        $out = [];
        try {
            foreach ($headers as $name => $value) {
                $key = strtolower((string) $name);
                $text = is_array($value) ? implode(', ', $value) : (string) $value;
                $out[$key] = self::isSecretHeader($key)
                    ? 'REDACTED'
                    : substr($text, 0, self::HEADER_VALUE_CAP);
            }
        } catch (\Throwable) {
            // fail open: a header we cannot read is one we do not send
        }

        return $out;
    }

    /**
     * What of the body goes on the wire: an object minus its credential-named
     * top-level keys (Merge restores them on retry); any other JSON as-is.
     */
    public static function travelingBody(mixed $body): mixed
    {
        if (!is_array($body) || array_is_list($body)) {
            return $body;
        }

        return array_filter(
            $body,
            static fn (string|int $key): bool => !self::isSecretField($key),
            ARRAY_FILTER_USE_KEY,
        );
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

    public static function healPayload(
        string $traceId,
        string $method,
        string $url,
        iterable $headers,
        mixed $body,
        int $statusCode,
        mixed $responseBody,
        bool $truncated,
        int $responseTimeMs,
    ): array {
        return [
            'traceId' => $traceId,
            'request' => [
                'method' => strtoupper($method),
                'url' => self::safeUrl($url),
                // an object even when empty: [] would encode as a JSON list
                'headers' => (object) self::safeHeaders($headers),
                'body' => self::travelingBody($body),
            ],
            'response' => [
                'statusCode' => $statusCode,
                'body' => $responseBody,
                'truncated' => $truncated,
            ],
            'responseTimeMs' => $responseTimeMs,
        ];
    }
}
