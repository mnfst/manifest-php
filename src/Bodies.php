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
        if (strlen($raw) > Gate::REQUEST_BODY_LIMIT) {
            return [null, false];
        }

        if (str_contains($contentType, 'json') || $contentType === '') {
            $parsed = Gate::parseJsonBody($raw);
            if ($parsed !== null) {
                return [$parsed, true];
            }
        }

        if ($contentType === self::FORM) {
            return [self::parseForm($raw), true];
        }

        return [null, false];
    }

    /**
     * Not parse_str: it rewrites dots and spaces in names to underscores and
     * reinterprets brackets, so a replay would rename the caller's fields. A
     * name is kept verbatim; a repeated name becomes a list.
     */
    public static function parseForm(string $raw): array|\stdClass
    {
        $out = [];
        foreach (explode('&', $raw) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = urldecode($name);
            $value = urldecode($value);
            if (!array_key_exists($name, $out)) {
                $out[$name] = $value;
            } elseif (is_array($out[$name])) {
                $out[$name][] = $value;
            } else {
                $out[$name] = [$out[$name], $value];
            }
        }

        return array_is_list($out) ? (object) $out : $out;
    }

    /** The inverse of parseForm; null when a value cannot be expressed as a form field. */
    public static function encodeForm(array $body): ?string
    {
        $pairs = [];
        foreach ($body as $name => $value) {
            foreach (is_array($value) && array_is_list($value) ? $value : [$value] as $item) {
                if (is_array($item) || is_object($item)) {
                    return null;
                }
                $pairs[] = urlencode((string) $name) . '=' . urlencode(self::scalarText($item));
            }
        }

        return implode('&', $pairs);
    }

    private static function scalarText(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };
    }

    public static function encodeRequestBody(mixed $body, string $contentType): ?string
    {
        if ($contentType === self::FORM) {
            if ($body instanceof \stdClass) {
                return self::encodeForm((array) $body);
            }
            if (!is_array($body) || array_is_list($body)) {
                return null;
            }

            return self::encodeForm($body);
        }

        try {
            // PRESERVE_ZERO_FRACTION: without it a float literal the caller sent as
            // 10.0 goes back on the wire as 10, and strict APIs reject the type change.
            return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        } catch (\Throwable) {
            return null;
        }
    }
}
