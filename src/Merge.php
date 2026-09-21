<?php declare(strict_types=1);

namespace Mnfst;

/**
 * Settle a healed body against the one the caller sent.
 *
 * Objects merge: the healed object decides every key it names, a key it omits
 * is dropped (deletion-by-omission is how a field gets removed), and keys that
 * never travelled — the credential-named ones withheld from the wire — are
 * restored from the caller's copy unless the healed object names them.
 * Anything else (lists, scalars, a body that changed type) is replaced
 * wholesale.
 *
 * Json preserves objects and lists, including empty ones. An object that
 * merges to nothing is returned as stdClass so it encodes as `{}`.
 */
final class Merge
{
    public static function healedBody(mixed $original, mixed $traveled, mixed $healed): mixed
    {
        if (!self::isObject($original) || !self::isObject($healed)) {
            return $healed;
        }

        $merged = self::keys($healed);
        $traveledKeys = self::isObject($traveled) ? self::keys($traveled) : [];

        foreach (self::keys($original) as $key => $value) {
            if (!array_key_exists($key, $traveledKeys) && !array_key_exists($key, $merged)) {
                $merged[$key] = $value;
            }
        }

        return array_is_list($merged) ? (object) $merged : $merged;
    }

    /** JSON objects use associative arrays or stdClass when array keys would imply a list. */
    private static function isObject(mixed $value): bool
    {
        return (is_array($value) && !array_is_list($value)) || $value instanceof \stdClass;
    }

    /** @return array<string|int, mixed> */
    private static function keys(mixed $object): array
    {
        return (array) $object;
    }
}
