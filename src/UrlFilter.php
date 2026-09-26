<?php declare(strict_types=1);

namespace Mnfst;

/**
 * Calls that never reach Manifest: neither healed nor tracked. An entry is a domain
 * (`stripe.com`) or a domain with a path (`stripe.com/v1/charges`); the domain covers its
 * subdomains either way, and the path matches whole segments.
 *
 * @phpstan-type Rule array{host: string, path: ?string}
 */
final class UrlFilter
{
    private const HOST = '/^(?:[a-z0-9_-]+(?:\.[a-z0-9_-]+)*|\[[0-9a-f:.]+\])$/';

    /**
     * One entry, normalized; null when it cannot be read. The scheme, port, query and
     * fragment are ignored.
     *
     * @return Rule|null
     */
    public static function parse(string $entry): ?array
    {
        $rest = (string) preg_replace('~^[a-z][a-z0-9+.-]*://~i', '', trim($entry));
        $rest = preg_split('/[?#]/', $rest)[0] ?? '';
        $slash = strpos($rest, '/');
        $authority = strtolower($slash === false ? $rest : substr($rest, 0, $slash));
        $path = $slash === false ? '' : rtrim(substr($rest, $slash), '/');
        $host = trim((string) preg_replace(['/^\*\./', '/:\d+$/'], '', $authority), '.');
        // `*` inside a path is reserved for a future segment wildcard, so it is refused today.
        if (preg_match(self::HOST, $host) !== 1 || str_contains($path, '*')) {
            return null;
        }

        $canonical = $path === '' ? '/' : self::canonicalPath($path);

        return ['host' => trim($host, '[]'), 'path' => $canonical === '/' ? null : $canonical];
    }

    /**
     * The path a server is likely to route: every percent-escape decoded (so `/%70rivate` and
     * `/private%2Fitem` read as `/private…`) and `.`/`..` segments resolved. Rules compare
     * against it, so an encoded spelling cannot slip past a denylist or into an allowlist.
     */
    public static function canonicalPath(string $path): string
    {
        $segments = [];
        foreach (array_slice(explode('/', rawurldecode($path)), 1) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.') {
                $segments[] = $segment;
            }
        }

        return '/' . implode('/', $segments);
    }

    /**
     * The non-blank entries of a comma-separated string or a list.
     *
     * @param list<string>|string|null $value
     * @return list<string>
     */
    public static function entries(array|string|null $value): array
    {
        $items = is_string($value) ? explode(',', $value) : ($value ?? []);
        $trimmed = array_map(static fn ($e): string => trim((string) $e), $items);

        return array_values(array_filter($trimmed, static fn (string $e): bool => $e !== ''));
    }

    /**
     * The readable rules among these entries, and the entries that were dropped.
     *
     * @param list<string> $entries
     * @return array{list<Rule>, list<string>}
     */
    public static function rules(array $entries): array
    {
        $rules = [];
        $invalid = [];
        foreach ($entries as $entry) {
            $rule = self::parse($entry);
            if ($rule === null) {
                $invalid[] = $entry;
            } else {
                $rules[] = $rule;
            }
        }

        return [$rules, $invalid];
    }

    /**
     * A denied call is excluded; with an allowlist, so is every call not on it. An unreadable
     * URL is not. `$allow` is null when no allowlist was given; an allowlist of only bad
     * entries still excludes.
     *
     * @param list<Rule>|null $allow
     * @param list<Rule> $deny
     */
    public static function excluded(?array $allow, array $deny, string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        $host = trim(rtrim(strtolower($host), '.'), '[]');
        $path = parse_url($url, PHP_URL_PATH);
        $path = self::canonicalPath(is_string($path) && $path !== '' ? $path : '/');
        if (self::matches($host, $path, $deny)) {
            return true;
        }

        return $allow !== null && !self::matches($host, $path, $allow);
    }

    /** @param list<Rule> $rules */
    private static function matches(string $host, string $path, array $rules): bool
    {
        foreach ($rules as $rule) {
            $hostMatches = $host === $rule['host'] || str_ends_with($host, '.' . $rule['host']);
            $pathMatches = $rule['path'] === null || $path === $rule['path']
                || str_starts_with($path, $rule['path'] . '/');
            if ($hostMatches && $pathMatches) {
                return true;
            }
        }

        return false;
    }
}
