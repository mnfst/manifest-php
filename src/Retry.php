<?php declare(strict_types=1);

namespace Mnfst;

/**
 * What to replay, built from a heal answer by the rules of CONTRACT.md "Apply".
 *
 * The retry is the original request with the healed parts swapped in: same
 * method, same headers unless the server set or removed one, same body unless
 * the server changed it, same URL unless the server moved it within the origin.
 * Credential values the SDK masked before sending (`REDACTED`) are restored
 * from the original request, never sent literally. Null means "do not retry".
 */
final class Retry
{
    public const MASK = 'REDACTED';

    /** These retry without a body when the healed body is nothing. */
    private const BODYLESS = ['GET', 'HEAD', 'DELETE', 'OPTIONS'];

    /** These never carry a body on a retry. */
    private const NEVER_BODY = ['GET', 'HEAD'];

    /**
     * @param string $url the URL to replay
     * @param array<string, list<string>> $headers the headers to replay, original names kept
     * @param string|null $body the encoded body, null for a bodyless retry
     */
    public function __construct(
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body,
    ) {
    }

    /**
     * @param string $method the original method, which the retry keeps
     * @param string $url the original URL, credentials included
     * @param iterable<string, string|list<string>> $headers the original headers
     * @param mixed $body the parsed original body (see Bodies::parseRequestBody)
     * @param bool $replayable whether the original body could be re-encoded
     * @param string $contentType the original content type, lowercased, without parameters
     * @param array<string, mixed> $result the decoded /v1/heal answer
     */
    public static function build(
        string $method,
        string $url,
        iterable $headers,
        mixed $body,
        bool $replayable,
        string $contentType,
        array $result,
    ): ?self {
        if (!in_array($result['status'] ?? null, ['patched', 'unverified'], true)) {
            return null;
        }
        $healed = $result['healedRequest'] ?? null;
        if (!is_array($healed) || !self::namesSomething($healed)) {
            return null;
        }

        $retryUrl = self::url($url, $healed);
        $retryHeaders = self::headers($headers, $healed);
        if ($retryUrl === null || $retryHeaders === null) {
            return null;
        }

        $method = strtoupper($method);
        if (in_array($method, self::NEVER_BODY, true)) {
            return new self($retryUrl, $retryHeaders, null);
        }

        $hasBody = array_key_exists('body', $healed);
        if (!$replayable && !$hasBody) {
            return null;   // an unreadable body needs a replacement before it can be retried
        }
        $merged = $hasBody ? Merge::healedBody($body, Wire::travelingBody($body), $healed['body']) : $body;
        if ($merged === null) {
            return in_array($method, self::BODYLESS, true) ? new self($retryUrl, $retryHeaders, null) : null;
        }
        $encoded = Bodies::encodeRequestBody($merged, $contentType);
        if ($encoded === null) {
            return null;
        }

        return new self($retryUrl, $retryHeaders, $encoded);
    }

    private static function namesSomething(array $healed): bool
    {
        foreach (['url', 'headers', 'body'] as $key) {
            if (array_key_exists($key, $healed)) {
                return true;
            }
        }

        return false;
    }

    /** The healed URL if it stays on the original origin, masked values restored; else null. */
    private static function url(string $original, array $healed): ?string
    {
        if (!array_key_exists('url', $healed)) {
            return $original;
        }
        $url = $healed['url'];
        if (!is_string($url) || $url === '') {
            return null;
        }
        $healedParts = parse_url($url);
        $originalParts = parse_url($original);
        if ($healedParts === false || $originalParts === false) {
            return null;
        }
        if (isset($healedParts['user']) || isset($healedParts['pass'])) {
            return null;
        }
        if (self::origin($healedParts) === null || self::origin($healedParts) !== self::origin($originalParts)) {
            return null;
        }

        return self::restoreQuery($url, $healedParts['query'] ?? '', $originalParts['query'] ?? '');
    }

    /** scheme://host:port with the default port made explicit, or null without a host. */
    private static function origin(array $parts): ?string
    {
        if (!isset($parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : null));

        return $scheme . '://' . strtolower($parts['host']) . ':' . ($port ?? '');
    }

    /**
     * Every `name=REDACTED` in the healed query takes the value the original
     * request sent for that name. A REDACTED with nothing to restore aborts
     * the retry: the SDK never sends that literal upstream.
     */
    private static function restoreQuery(string $url, string $healedQuery, string $originalQuery): ?string
    {
        if (!str_contains($healedQuery, self::MASK)) {
            return $url;
        }
        $originals = [];
        foreach (Wire::queryPairs($originalQuery) as [$name, $value]) {
            $originals[urldecode($name)] ??= $value;
        }
        $restored = [];
        foreach (Wire::queryPairs($healedQuery) as [$name, $value]) {
            if ($value === self::MASK) {
                $value = $originals[urldecode($name)] ?? null;
                if ($value === null) {
                    return null;
                }
            }
            $restored[] = $value === null ? $name : $name . '=' . $value;
        }
        $base = explode('#', explode('?', $url, 2)[0], 2)[0];

        return $base . ($restored === [] ? '' : '?' . implode('&', $restored));
    }

    /**
     * The original headers, minus Content-Length (recomputed by the client),
     * with the healed ones set or removed case-insensitively. A masked value
     * keeps the original; a healed header that is not a string aborts.
     *
     * @param iterable<string, string|list<string>> $original
     * @return array<string, list<string>>|null
     */
    private static function headers(iterable $original, array $healed): ?array
    {
        $out = [];
        foreach ($original as $name => $value) {
            $name = (string) $name;
            if (strtolower($name) === 'content-length') {
                continue;
            }
            $out[$name] = is_array($value) ? array_values(array_map('strval', $value)) : [(string) $value];
        }
        if (!array_key_exists('headers', $healed)) {
            return $out;
        }
        $changes = $healed['headers'];
        if ($changes === null) {
            return $out;
        }
        if (!is_array($changes) || array_is_list($changes)) {
            return null;
        }
        foreach ($changes as $name => $value) {
            $name = (string) $name;
            if ($value === self::MASK) {
                continue;
            }
            if ($value !== null && !is_string($value)) {
                return null;
            }
            foreach (array_keys($out) as $existing) {
                if (strcasecmp($existing, $name) === 0) {
                    unset($out[$existing]);
                }
            }
            if ($value !== null) {
                $out[$name] = [$value];
            }
        }

        return $out;
    }
}
