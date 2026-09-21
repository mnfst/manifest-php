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
 * original origin and never with credentials; a healed header sets or, when
 * null, removes; the SDK's own mask value never goes back on the wire; the
 * body merges through Merge; a body that merges to nothing retries bodyless
 * on GET, HEAD, DELETE and OPTIONS and is not retried on any other method.
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
        array $originalHeaders = [],
    ): ?array {
        if (!$replayable || !is_array($result) || !in_array($result['status'] ?? null, ['patched', 'unverified'], true)) {
            return null;
        }
        $healed = $result['healedRequest'] ?? null;
        if (!is_array($healed) || !array_intersect_key($healed, ['url' => 1, 'headers' => 1, 'body' => 1])) {
            return null;
        }

        $target = self::url($url, $healed['url'] ?? null);
        $headers = self::headers($healed['headers'] ?? null, $originalHeaders);
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

    /** The healed URL when it stays on the original origin and carries no userinfo. */
    private static function url(string $original, mixed $healed): ?string
    {
        if ($healed === null) {
            return $original;
        }
        if (!is_string($healed) || preg_match('/[\\\\\x00-\x20\x7f]/', $healed)) {
            return null;
        }
        $from = parse_url($original);
        $to = parse_url($healed);
        if ($from === false || $to === false || isset($to['user']) || isset($to['pass'])) {
            return null;
        }
        if (!in_array(strtolower($to['scheme'] ?? ''), ['http', 'https'], true) || empty($to['host'])) {
            return null;
        }
        foreach (['scheme', 'host'] as $part) {
            if (strtolower((string) ($from[$part] ?? '')) !== strtolower($to[$part])) {
                return null;
            }
        }
        $defaultPort = strtolower($from['scheme'] ?? '') === 'https' ? 443 : 80;
        if (($from['port'] ?? $defaultPort) !== ($to['port'] ?? $defaultPort)) {
            return null;
        }

        return self::settleQuery($healed, $to['query'] ?? '', $from['query'] ?? '');
    }

    /**
     * The healed query, with what the server could not have decided put back.
     *
     * Credential values travel masked, so a `name=REDACTED` coming back takes
     * the original value again, and a credential the healed URL left out is
     * re-attached: the server only ever saw its name, so its absence is not a
     * decision — and a retry without the key is a certain 401. A mask with
     * nothing to restore abandons the retry rather than sending the literal.
     * The fragment never goes on the wire.
     */
    private static function settleQuery(string $url, string $healedQuery, string $originalQuery): ?string
    {
        $originals = [];
        foreach (Wire::queryPairs($originalQuery) as [$name, $value]) {
            $originals[urldecode($name)][] = [$name, $value];
        }

        $pairs = [];
        $seen = [];
        foreach (Wire::queryPairs($healedQuery) as [$name, $value]) {
            $decoded = urldecode($name);
            $index = $seen[$decoded] ?? 0;
            $seen[$decoded] = $index + 1;
            if ($value !== null && urldecode($value) === self::MASK) {
                $value = $originals[$decoded][$index][1] ?? null;
                if ($value === null) {
                    return null;
                }
            }
            $pairs[] = $value === null ? $name : $name . '=' . $value;
        }
        foreach ($originals as $decoded => $values) {
            if (!isset($seen[$decoded]) && Wire::isSecretField((string) $decoded)) {
                foreach ($values as [$name, $value]) {
                    $pairs[] = $value === null ? $name : $name . '=' . $value;
                }
            }
        }

        $base = explode('#', explode('?', $url, 2)[0], 2)[0];

        return $base . ($pairs === [] ? '' : '?' . implode('&', $pairs));
    }

    /** @return array<string, ?string>|null set (string) or remove (null) per name; null when malformed */
    private static function headers(mixed $healed, array $original): ?array
    {
        if ($healed === null || Json::isEmptyObject($healed)) {
            return [];
        }
        if (!is_array($healed) || ($healed !== [] && array_is_list($healed))) {
            return null;
        }
        $out = [];
        $known = array_change_key_case($original, CASE_LOWER);
        foreach ($healed as $name => $value) {
            if (!is_string($name) || !preg_match('/^[!#$%&\x27*+.^_`|~0-9A-Za-z-]+$/D', $name)
                || ($value !== null && (!is_string($value) || preg_match('/[\r\n\x00]/', $value)))) {
                return null;
            }
            if ($value === self::MASK && !array_key_exists(strtolower($name), $known)) {
                return null;
            }
            if ($value !== self::MASK) {
                $out[(string) $name] = $value;
            }
        }

        return $out;
    }

    /** Apply header deltas once for every client; the transport recalculates framing. */
    public static function headersFor(array $original, array $plan): array
    {
        $headers = array_change_key_case($original, CASE_LOWER);
        foreach ($plan['headers'] as $name => $value) {
            $name = strtolower($name);
            if ($value === null) {
                unset($headers[$name]);
            } else {
                $headers[$name] = [$value];
            }
        }
        unset($headers['content-length'], $headers['transfer-encoding']);

        return $headers;
    }
}
