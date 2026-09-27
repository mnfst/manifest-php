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
    public const MASK = Masked::MASK;

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
        ?Masked $sent = null,
    ): ?array {
        if (!$replayable || !is_array($result) || !in_array($result['status'] ?? null, ['patched', 'unverified'], true)) {
            return null;
        }
        $healed = $result['healedRequest'] ?? null;
        if (!is_array($healed) || !array_intersect_key($healed, ['url' => 1, 'headers' => 1, 'body' => 1])) {
            return null;
        }

        // What the server saw; the Healer passes the very copy it sent.
        $sent ??= Masked::request($method, $url, $originalHeaders, $body);
        $target = self::url($url, $healed['url'] ?? null, $sent);
        $headers = self::headers($healed['headers'] ?? null, $originalHeaders, $sent);
        if ($target === null || $headers === null) {
            return null;
        }

        $method = strtoupper($method);
        $merged = $body;
        if (array_key_exists('body', $healed)) {
            [$restored, $merged] = self::restoreBody($body, $sent, $healed['body']);
            if (!$restored) {
                return null;
            }
        }
        if ($merged === null || in_array($method, self::NEVER_BODIED, true)) {
            return in_array($method, self::BODYLESS, true)
                ? ['url' => $target, 'headers' => $headers, 'body' => null]
                : null;
        }

        $encoded = Bodies::encodeRequestBody($merged, $contentType);

        return $encoded === null ? null : ['url' => $target, 'headers' => $headers, 'body' => $encoded];
    }

    /** The healed URL when it stays on the original origin and carries no userinfo. */
    /**
     * The healed body with every masked value the heal left as it was sent put back.
     *
     * The healed body is authoritative: a value an operation changed or removed stays
     * so. A value still equal to what was sent (REDACTED, or a string masked partway)
     * takes the original back. A mask with nothing to restore is never sent: the retry
     * is refused. A body masked whole could not be changed by the heal, so the original
     * goes.
     *
     * @return array{0: bool, 1: mixed}
     */
    private static function restoreBody(mixed $original, Masked $sent, mixed $healed): array
    {
        if ($sent->body === self::MASK && $original !== self::MASK) {
            return $healed === self::MASK ? [true, $original] : [false, null];
        }
        foreach ($sent->at('body') as $pointer) {
            $path = self::pointer($pointer);
            if ($path === [] || !self::has($original, $path)) {
                continue;
            }
            // Through a list, an index only names the same element if the heal left that
            // element exactly as sent: otherwise a secret could land on another element.
            if (!self::sameListElements($healed, $sent->body, $path)) {
                return [false, null];
            }
            // Left as sent, or left out of an object that stayed: the server never saw the
            // value, so that is not a decision (the same rule as a query credential).
            $parent = array_slice($path, 0, -1);
            $leftOut = !self::has($healed, $path) && self::has($healed, $parent) && self::isObject(self::get($healed, $parent));
            if ($leftOut || (self::has($healed, $path) && self::get($healed, $path) === self::get($sent->body, $path))) {
                $healed = self::set($healed, $path, self::get($original, $path));
            }
        }
        if (self::newMask($healed, $original, [])) {
            return [false, null];
        }

        return [true, $healed];
    }

    /** Every list on the way to `$path` has the same length, and the element on the way is unchanged. */
    private static function sameListElements(mixed $healed, mixed $sent, array $path): bool
    {
        for ($i = 0; $i < count($path); $i++) {
            $prefix = array_slice($path, 0, $i);
            $container = self::get($sent, $prefix);
            if (!is_array($container) || !array_is_list($container)) {
                continue;
            }
            $healedContainer = self::has($healed, $prefix) ? self::get($healed, $prefix) : null;
            if (!is_array($healedContainer) || !array_is_list($healedContainer) || count($healedContainer) !== count($container)) {
                return false;
            }
            $element = array_slice($path, 0, $i + 1);
            if (!self::has($healed, $element) || Json::encode(self::get($healed, $element)) !== Json::encode(self::get($sent, $element))) {
                return false;
            }
        }

        return true;
    }

    private static function isObject(mixed $value): bool
    {
        return $value instanceof \stdClass || (is_array($value) && ($value === [] || !array_is_list($value)));
    }

    /** A mask at an address where the original holds none: it would reach the API. */
    private static function newMask(mixed $healed, mixed $original, array $path): bool
    {
        if (is_string($healed)) {
            if (!str_contains($healed, self::MASK)) {
                return false;
            }
            $was = self::has($original, $path) ? self::get($original, $path) : null;

            return !(is_string($was) && str_contains($was, self::MASK));
        }
        if (is_array($healed) || $healed instanceof \stdClass) {
            foreach ((array) $healed as $key => $child) {
                if (self::newMask($child, $original, [...$path, (string) $key])) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> RFC 6901 */
    private static function pointer(string $pointer): array
    {
        if ($pointer === '') {
            return [];
        }

        return array_map(static fn (string $p): string => str_replace(['~1', '~0'], ['/', '~'], $p), array_slice(explode('/', $pointer), 1));
    }

    private static function has(mixed $value, array $path): bool
    {
        foreach ($path as $key) {
            if (is_array($value) && array_key_exists($key, $value)) {
                $value = $value[$key];
            } elseif ($value instanceof \stdClass && property_exists($value, $key)) {
                $value = $value->{$key};
            } else {
                return false;
            }
        }

        return true;
    }

    private static function get(mixed $value, array $path): mixed
    {
        foreach ($path as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : ($value instanceof \stdClass ? ($value->{$key} ?? null) : null);
        }

        return $value;
    }

    private static function set(mixed $value, array $path, mixed $new): mixed
    {
        if ($path === []) {
            return $new;
        }
        $key = array_shift($path);
        if (is_array($value)) {
            $value[$key] = self::set($value[$key] ?? null, $path, $new);
        } elseif ($value instanceof \stdClass) {
            $value = clone $value;
            $value->{$key} = self::set($value->{$key} ?? null, $path, $new);
        }

        return $value;
    }

    private static function url(string $original, mixed $healed, Masked $sent): ?string
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

        // A masked path segment (a webhook secret) the heal left masked takes the original back.
        $path = (string) ($to['path'] ?? '');
        $originalPath = (string) ($from['path'] ?? '');
        $segments = explode('/', $path);
        $originals = explode('/', $originalPath);
        // Masked segments are restored by position: a heal that changed the path's length
        // could put a secret in another segment.
        if ($sent->at('path') !== [] && count($segments) !== count($originals)) {
            return null;
        }
        foreach ($sent->at('path') as $index) {
            if (($segments[(int) $index] ?? null) === self::MASK && isset($originals[(int) $index])) {
                $segments[(int) $index] = $originals[(int) $index];
            }
        }
        foreach ($segments as $i => $segment) {
            if (str_contains($segment, self::MASK) && !str_contains($originals[$i] ?? '', self::MASK)) {
                return null;
            }
        }
        $restored = implode('/', $segments);
        $base = explode('#', explode('?', $healed, 2)[0], 2)[0];
        $healed = substr($base, 0, strlen($base) - strlen($path)) . $restored . substr($healed, strlen($base));

        return self::settleQuery($healed, $to['query'] ?? '', $from['query'] ?? '', $sent->at('query'));
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
    /** @param list<string> $maskedNames the query names that travelled masked */
    private static function settleQuery(string $url, string $healedQuery, string $originalQuery, array $maskedNames): ?string
    {
        $originals = [];
        foreach (Wire::queryPairs($originalQuery) as [$name, $value]) {
            $originals[urldecode($name)][] = [$name, $value];
        }

        $healedPairs = Wire::queryPairs($healedQuery);
        // A masked name is restored by occurrence: if the heal changed how many times it
        // appears, which original goes where is unknown.
        $counts = [];
        foreach ($healedPairs as [$name]) {
            $counts[urldecode($name)] = ($counts[urldecode($name)] ?? 0) + 1;
        }
        foreach ($maskedNames as $masked) {
            if (isset($counts[$masked]) && $counts[$masked] !== count($originals[$masked] ?? [])) {
                return null;
            }
        }

        $pairs = [];
        $seen = [];
        foreach ($healedPairs as [$name, $value]) {
            $decoded = urldecode($name);
            $index = $seen[$decoded] ?? 0;
            $seen[$decoded] = $index + 1;
            if ($value !== null && urldecode($value) === self::MASK) {
                $value = $originals[$decoded][$index][1] ?? null;
                if ($value === null) {
                    return null;
                }
            } elseif ($value !== null && str_contains(urldecode($value), self::MASK)
                && !str_contains(urldecode((string) ($originals[$decoded][$index][1] ?? '')), self::MASK)) {
                return null;   // a value holding the mask would reach the API
            }
            $pairs[] = $value === null ? $name : $name . '=' . $value;
        }
        foreach ($originals as $decoded => $values) {
            if (!isset($seen[$decoded]) && in_array((string) $decoded, $maskedNames, true)) {
                foreach ($values as [$name, $value]) {
                    $pairs[] = $value === null ? $name : $name . '=' . $value;
                }
            }
        }

        $base = explode('#', explode('?', $url, 2)[0], 2)[0];

        return $base . ($pairs === [] ? '' : '?' . implode('&', $pairs));
    }

    /** @return array<string, ?string>|null set (string) or remove (null) per name; null when malformed */
    private static function headers(mixed $healed, array $original, Masked $sent): ?array
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
            $lower = strtolower($name);
            if ($value === self::MASK && !array_key_exists($lower, $known)) {
                return null;
            }
            // Unchanged from what was sent (REDACTED, or masked partway): the original header stays.
            if ($value === self::MASK || ($value !== null && in_array($lower, $sent->at('header'), true) && $value === ($sent->headers[$lower] ?? null))) {
                continue;
            }
            if ($value !== null && str_contains($value, self::MASK) && !str_contains((string) ($known[$lower] ?? ''), self::MASK)) {
                return null;   // the heal changed a masked header: its credential cannot be put back
            }
            $out[(string) $name] = $value;
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
