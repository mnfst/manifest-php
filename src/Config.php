<?php declare(strict_types=1);

namespace Mnfst;

/**
 * Options resolution: arguments beat environment beats defaults.
 *
 * The surface is deliberately tiny: credentials, server, and a local
 * observability hook. Everything that is policy lives server-side, where it is
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

    public function __construct(
        public readonly ?string $apiKey,
        public readonly string $baseUrl,
        public readonly mixed $onHeal = null,
    ) {
    }

    public static function resolve(?string $apiKey = null, ?string $url = null, ?callable $onHeal = null): self
    {
        $key = $apiKey ?? (getenv('MNFST_KEY') ?: null);
        $base = $url ?? (getenv('MNFST_URL') ?: null) ?? self::HOSTED_URL;

        return new self($key, rtrim($base, '/'), $onHeal);
    }
}
