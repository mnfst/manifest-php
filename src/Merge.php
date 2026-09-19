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
 * A healed `{}` decodes to [] and is indistinguishable from an empty list, so
 * against an object original it is read as an empty object, and an object
 * that merges to nothing is returned as stdClass so it encodes as `{}`.
 */
final class Merge
{
    public static function healedBody(mixed $original, mixed $traveled, mixed $healed): mixed
    {
        if (!self::isObject($original) || (!self::isObject($healed) && $healed !== [])) {
            return $healed;
        }

        $merged = $healed;
        $traveledKeys = self::isObject($traveled) ? $traveled : [];

        foreach ($original as $key => $value) {
            if (!array_key_exists($key, $traveledKeys) && !array_key_exists($key, $merged)) {
                $merged[$key] = $value;
            }
        }

        return $merged === [] ? new \stdClass() : $merged;
    }

    private static function isObject(mixed $value): bool
    {
        return is_array($value) && !array_is_list($value);
    }
}
