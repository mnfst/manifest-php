<?php declare(strict_types=1);

namespace Mnfst;

/**
 * Options resolution: arguments beat environment beats defaults.
 *
 * The surface is deliberately tiny: credentials, server, a local
 * observability hook, and the calls Manifest never sees (allowlist / denylist). Everything that is policy lives server-side, where it is
 * editable without a deploy.
 */
final class Config
{
    public const HOSTED_URL = 'https://api.manifest.build';

    /**
     * Hard client-side cap on a heal round-trip. Not configuration: fail-open
     * needs a bound even when the server misbehaves. 10 rather than the other
     * SDKs' 60 because a PHP heal runs inside a web request, and a web server
     * commonly cuts the request off at 30 seconds.
     */
    public const HEAL_TIMEOUT_SECONDS = 10;

    public const HELLO_TIMEOUT_SECONDS = 5;

    /**
     * The outcome report runs after the retry already answered, still inside
     * the web request, so it gets the Node SDK's 5 seconds rather than the
     * heal's 10: it is evidence for the dashboard, not the user's response.
     */
    public const REPORT_TIMEOUT_SECONDS = 5;

    public function __construct(
        public readonly ?string $apiKey,
        public readonly string $baseUrl,
        public readonly mixed $onHeal = null,
        /**
         * Only these calls reach Manifest; null when no allowlist was given (every call is
         * eligible). An allowlist of only unreadable entries is an empty list: nothing passes.
         *
         * @var list<array{host: string, path: ?string}>|null
         */
        public readonly ?array $allowlist = null,
        /** @var list<array{host: string, path: ?string}> these calls never reach Manifest */
        public readonly array $denylist = [],
        /** @var list<string> the entries that were dropped, for Manifest::start() to report */
        public readonly array $ignoredEntries = [],
    ) {
    }

    /**
     * @param list<string>|string|null $allowlist default MNFST_ALLOWLIST, comma-separated
     * @param list<string>|string|null $denylist default MNFST_DENYLIST, comma-separated
     */
    public static function resolve(
        ?string $apiKey = null,
        ?string $url = null,
        ?callable $onHeal = null,
        array|string|null $allowlist = null,
        array|string|null $denylist = null,
    ): self {
        // An empty argument is what a framework's config() yields for an unset or
        // blanked variable (phpunit.xml's `<env name="MNFST_KEY" value=""/>`): unset.
        $key = $apiKey === null ? self::env('MNFST_KEY') : (self::blank($apiKey) ? null : $apiKey);
        $base = (self::blank($url) ? self::env('MNFST_URL') : $url) ?? self::HOSTED_URL;

        $allowEntries = self::entries($allowlist, 'MNFST_ALLOWLIST');
        [$allow, $badAllow] = UrlFilter::rules($allowEntries);
        [$deny, $badDeny] = UrlFilter::rules(self::entries($denylist, 'MNFST_DENYLIST'));

        return new self(
            $key,
            rtrim($base, '/'),
            $onHeal,
            $allowEntries === [] ? null : $allow,
            $deny,
            [...$badAllow, ...$badDeny],
        );
    }

    /**
     * An option beats its env var, like the key and URL; a blank one is unset and falls back to it.
     *
     * @param list<string>|string|null $option
     * @return list<string>
     */
    private static function entries(array|string|null $option, string $env): array
    {
        return UrlFilter::entries($option) ?: UrlFilter::entries(self::env($env));
    }

    private static function blank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }

    /**
     * An environment variable wherever the framework put it. Laravel and
     * Symfony load .env files into $_ENV and $_SERVER without putenv(), so
     * getenv() alone sees nothing of a key set the way their docs say to.
     */
    public static function env(string $name): ?string
    {
        foreach ([$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)] as $value) {
            if (is_string($value)) {
                return self::blank($value) ? null : $value;
            }
        }

        return null;
    }
}
