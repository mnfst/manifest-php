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
            $fields = self::parseForm($raw);

            return $fields === null ? [null, false] : [$fields, true];
        }

        return [null, false];
    }

    public static function encodeRequestBody(mixed $body, string $contentType): ?string
    {
        if ($contentType === self::FORM) {
            if (!is_array($body) || array_is_list($body)) {
                return null;
            }

            return self::encodeForm($body);
        }

        return Json::encode($body);
    }

    /** More fields than this is a payload, not a form. */
    private const FORM_FIELD_LIMIT = 100000;

    private const FORM_DEPTH_LIMIT = 64;

    /**
     * Form fields the way the Node SDK reads them, so the server sees the same
     * structure from every SDK: keys verbatim (`user.name` stays `user.name`,
     * where parse_str would make it `user_name`), a repeated plain key becomes
     * a list instead of keeping the last value, `a[]` appends and `a[k]` nests.
     * Null for a body that is not UTF-8, mixes list and object use of a key,
     * or exceeds the structural limits: reported, never retried.
     *
     * @return array<string, mixed>|null
     */
    public static function parseForm(string $raw): ?array
    {
        if (preg_match('//u', $raw) !== 1) {
            return null;
        }
        $body = [];
        $fields = 0;
        foreach (explode('&', $raw) as $pair) {
            if ($pair === '') {
                continue;
            }
            if (++$fields > self::FORM_FIELD_LIMIT) {
                return null;
            }
            [$rawKey, $rawValue] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($rawKey);
            $value = urldecode($rawValue);
            if (preg_match('//u', $key . $value) !== 1) {
                return null;   // percent-encoded bytes that are not UTF-8, as decodeURIComponent would refuse
            }
            $path = self::formPath($key);
            if ($path === null || !self::putFormValue($body, $path, $value)) {
                return null;
            }
        }

        return $body;
    }

    /**
     * `a[b][]` → ['a', 'b', '']. Null for a key without a root, with a stray
     * bracket, or with text after the brackets.
     *
     * @return list<string>|null
     */
    private static function formPath(string $key): ?array
    {
        $bracket = strpos($key, '[');
        $root = $bracket === false ? $key : substr($key, 0, $bracket);
        if ($root === '' || str_contains($root, ']')) {
            return null;
        }
        $path = [$root];
        $offset = strlen($root);
        while ($offset < strlen($key)) {
            if ($key[$offset] !== '[') {
                return null;
            }
            $end = strpos($key, ']', $offset + 1);
            if ($end === false) {
                return null;
            }
            $part = substr($key, $offset + 1, $end - $offset - 1);
            if (str_contains($part, '[')) {
                return null;
            }
            $path[] = $part;
            $offset = $end + 1;
        }
        if (count($path) > self::FORM_DEPTH_LIMIT) {
            return null;
        }

        return $path;
    }

    /**
     * @param array<string|int, mixed> $root
     * @param list<string> $path
     */
    private static function putFormValue(array &$root, array $path, string $value): bool
    {
        $current = &$root;
        $count = count($path);
        foreach ($path as $index => $part) {
            $last = $index === $count - 1;
            $isList = $current !== [] && array_is_list($current);
            $wantsIndex = $part === '' || ctype_digit($part);
            if ($isList && !$wantsIndex) {
                return false;   // `a[]=1&a[k]=2`: a list cannot take a named key
            }
            if (!$isList && $current !== [] && $wantsIndex && !array_key_exists($part, $current)) {
                return false;   // `a[k]=1&a[]=2`: an object cannot take a list index
            }
            $key = $part === '' ? count($current) : ($wantsIndex ? (int) $part : $part);
            if ($last) {
                if (!array_key_exists($key, $current)) {
                    $current[$key] = $value;
                } elseif (is_string($current[$key])) {
                    $current[$key] = [$current[$key], $value];
                } elseif (is_array($current[$key]) && array_is_list($current[$key]) && self::allStrings($current[$key])) {
                    $current[$key][] = $value;
                } else {
                    return false;
                }
                continue;
            }
            if (!array_key_exists($key, $current)) {
                $current[$key] = [];
            } elseif (!is_array($current[$key])) {
                return false;
            }
            $current = &$current[$key];
        }

        return true;
    }

    private static function allStrings(array $values): bool
    {
        foreach ($values as $value) {
            if (!is_string($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The Node SDK's serialisation: lists as `key[0]`, objects as `key[name]`,
     * null as empty, booleans as words. Null when a value cannot travel as a
     * form field.
     *
     * @param array<string|int, mixed> $body
     */
    private static function encodeForm(array $body): ?string
    {
        $pairs = [];
        $fields = 0;
        $append = static function (mixed $value, string $key, int $depth) use (&$append, &$pairs, &$fields): bool {
            if (++$fields > self::FORM_FIELD_LIMIT || $depth > self::FORM_DEPTH_LIMIT) {
                return false;
            }
            if (Json::isEmptyObject($value)) {
                return true;
            }
            if (is_array($value)) {
                foreach ($value as $name => $child) {
                    if (!$append($child, $key . '[' . $name . ']', $depth + 1)) {
                        return false;
                    }
                }

                return true;
            }
            if ($value === null) {
                $value = '';
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_float($value) && !is_finite($value)) {
                return false;
            } elseif (!is_scalar($value)) {
                return false;
            }
            $pairs[] = urlencode($key) . '=' . urlencode((string) $value);

            return true;
        };
        foreach ($body as $name => $value) {
            if (!$append($value, (string) $name, 1)) {
                return null;
            }
        }

        return implode('&', $pairs);
    }
}
