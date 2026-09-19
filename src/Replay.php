<?php declare(strict_types=1);

namespace Mnfst;

/**
 * Turn a heal answer into the one retry the hook may send — or null, meaning
 * the attempt is not replayed and must be reported as not_attempted.
 *
 * The server heals the whole request: operations address the query string,
 * headers and path as well as the body, and `healedRequest` carries all four.
 * A retry that applied only the body would replay the original defect, and
 * the recurrence would be recorded as evidence against the patch.
 *
 * Rules, shared with the Node SDK: a healed URL is honoured only within the
 * original origin and never with userinfo; the query credentials the SDK
 * masked on the wire (and the server therefore dropped or echoed as the mask)
 * are restored from the original URL unless the healed URL carries a real
 * value for them; a healed header sets or, when null, removes; the SDK's own
 * mask value never goes back on the wire; the body merges through Merge; a
 * body that merges to nothing retries bodyless on GET, HEAD, DELETE and
 * OPTIONS and is not retried on any other method.
 */
final class Replay
{
    public const MASK = 'REDACTED';

    private const BODYLESS = ['GET', 'HEAD', 'DELETE', 'OPTIONS'];
    private const NEVER_BODIED = ['GET', 'HEAD'];

    /**
     * @return array{url: string, headers: array<string, ?string>, body: ?string}|null
     */
    public static function plan(
        string $method,
        string $url,
        mixed $body,
        bool $replayable,
        string $contentType,
        mixed $result,
    ): ?array {
        if (!$replayable || !is_array($result) || !in_array($result['status'] ?? null, ['patched', 'unverified'], true)) {
            return null;
        }
        $healed = $result['healedRequest'] ?? null;
        if (!is_array($healed) || !array_intersect_key($healed, ['url' => 1, 'headers' => 1, 'body' => 1])) {
            return null;
        }

        $target = self::url($url, $healed['url'] ?? null);
        $headers = self::headers($healed['headers'] ?? null);
        if ($target === null || $headers === null) {
            return null;
        }

        $method = strtoupper($method);
        $merged = array_key_exists('body', $healed)
            ? Merge::healedBody($body, Wire::travelingBody($body), $healed['body'])
            : $body;
        if ($merged === null || in_array($method, self::NEVER_BODIED, true)) {
            return in_array($method, self::BODYLESS, true)
                ? ['url' => $target, 'headers' => $headers, 'body' => null]
                : null;
        }

        $encoded = Bodies::encodeRequestBody($merged, $contentType);

        return $encoded === null ? null : ['url' => $target, 'headers' => $headers, 'body' => $encoded];
    }

    /**
     * The healed URL when it stays on the original origin and carries no
     * userinfo, with the caller's own query credentials put back.
     */
    private static function url(string $original, mixed $healed): ?string
    {
        if ($healed === null) {
            return $original;
        }
        if (!is_string($healed)) {
            return null;
        }
        $from = parse_url($original);
        $to = parse_url($healed);
        if ($from === false || $to === false || isset($to['user']) || isset($to['pass'])) {
            return null;
        }
        foreach (['scheme', 'host', 'port'] as $part) {
            if (strtolower((string) ($from[$part] ?? '')) !== strtolower((string) ($to[$part] ?? ''))) {
                return null;
            }
        }

        $query = self::restoredQuery(
            Bodies::parseForm($from['query'] ?? ''),
            Bodies::parseForm($to['query'] ?? ''),
        );
        $encoded = $query === [] ? null : Bodies::encodeForm($query);
        if ($query !== [] && $encoded === null) {
            return null;
        }
        $scheme = isset($to['scheme']) ? $to['scheme'] . '://' : '';
        $port = isset($to['port']) ? ':' . $to['port'] : '';

        return $scheme . ($to['host'] ?? '') . $port . ($to['path'] ?? '') . ($encoded === null ? '' : '?' . $encoded);
    }

    /**
     * The healed query decides every parameter it names with a real value. A
     * credential-named parameter it omits, or echoes as the mask, comes back
     * from the original; a mask with nothing to restore is dropped.
     */
    private static function restoredQuery(array $original, array $healed): array
    {
        foreach ($original as $name => $value) {
            $served = $healed[$name] ?? null;
            $needsRestore = $served === null || self::isMasked($served);
            if ($needsRestore && Wire::isSecretHeader((string) $name)) {
                $healed[$name] = $value;
            }
        }

        return array_filter($healed, static fn (mixed $value): bool => !self::isMasked($value));
    }

    private static function isMasked(mixed $value): bool
    {
        return $value === self::MASK || (is_array($value) && in_array(self::MASK, $value, true));
    }

    /** @return array<string, ?string>|null set (string) or remove (null) per name; null when malformed */
    private static function headers(mixed $healed): ?array
    {
        if ($healed === null) {
            return [];
        }
        if (!is_array($healed) || ($healed !== [] && array_is_list($healed))) {
            return null;
        }
        $out = [];
        foreach ($healed as $name => $value) {
            if ($value !== null && !is_string($value)) {
                return null;
            }
            if ($value !== self::MASK) {
                $out[(string) $name] = $value;
            }
        }

        return $out;
    }
}
