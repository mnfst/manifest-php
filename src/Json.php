<?php declare(strict_types=1);

namespace Mnfst;

use JsonException;
use stdClass;

/**
 * JSON the way it went on the wire. json_decode(…, true) turns an empty
 * object into an empty array, which re-encodes as `[]`: a field an API
 * declared as an object would come back as a list on the retry. Objects with
 * keys become arrays as usual; an empty object stays a stdClass.
 */
final class Json
{
    public const DEPTH = 64;

    public const ENCODE_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /** @throws JsonException */
    public static function decode(string $raw): mixed
    {
        return self::arrays(json_decode($raw, false, self::DEPTH, JSON_THROW_ON_ERROR));
    }

    /** Null when the value cannot be encoded (INF, bad UTF-8, too deep). */
    public static function encode(mixed $value): ?string
    {
        try {
            return json_encode($value, self::ENCODE_FLAGS | JSON_THROW_ON_ERROR, self::DEPTH);
        } catch (JsonException) {
            return null;
        }
    }

    /** An object with no keys: the one JSON value an array cannot represent. */
    public static function isEmptyObject(mixed $value): bool
    {
        return $value instanceof stdClass && get_object_vars($value) === [];
    }

    private static function arrays(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            if ($properties === []) {
                return $value;
            }
            foreach ($properties as $key => $child) {
                $properties[$key] = self::arrays($child);
            }

            return $properties;
        }
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $value[$key] = self::arrays($child);
            }
        }

        return $value;
    }
}
