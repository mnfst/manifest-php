<?php declare(strict_types=1);

namespace Mnfst;

/**
 * Read and re-encode the two body forms the SDK can repair: JSON and
 * application/x-www-form-urlencoded. Bytes that cannot be parsed into a
 * structure we can re-encode are reported but never retried.
 */
final class Bodies
{
    public const JSON = 'application/json';
    public const FORM = 'application/x-www-form-urlencoded';

    public static function contentTypeOf(iterable $headers): string
    {
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) !== 'content-type') {
                continue;
            }
            $text = is_array($value) ? ($value[0] ?? '') : (string) $value;

            return strtolower(trim(explode(';', $text)[0]));
        }

        return '';
    }

    /** @return array{0: mixed, 1: bool} the parsed body and whether it can be replayed */
    public static function parseRequestBody(?string $raw, string $contentType): array
    {
        if ($raw === null || $raw === '') {
            return [null, true];
        }

        if (str_contains($contentType, 'json') || $contentType === '') {
            $parsed = Gate::parseJsonBody($raw);
            if ($parsed !== null) {
                return [$parsed, true];
            }
        }

        if ($contentType === self::FORM) {
            parse_str($raw, $pairs);

            return [$pairs, true];
        }

        return [null, false];
    }

    public static function encodeRequestBody(mixed $body, string $contentType): ?string
    {
        if ($contentType === self::FORM) {
            if (!is_array($body) || array_is_list($body)) {
                return null;
            }

            return http_build_query($body);
        }

        try {
            return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable) {
            return null;
        }
    }
}
