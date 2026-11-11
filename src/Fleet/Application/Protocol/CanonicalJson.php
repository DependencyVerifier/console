<?php

declare(strict_types=1);

namespace Fleet\Application\Protocol;

/**
 * RFC 8785 (JCS) canonical JSON, for the values the protocol signs (strings, integers, booleans, null, arrays and
 * objects; no floats, so no number serialization rules): object members sorted by key, no whitespace, slashes and
 * non-ASCII characters left as they are. The console signs `order` with it (protocol §7.1).
 */
final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(self::sorted($value), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    private static function sorted(mixed $value): mixed
    {
        if (\is_float($value)) {
            throw new \InvalidArgumentException('A signed value never holds a float.');
        }

        if ($value instanceof \stdClass) {
            // An explicit map, `{}` when empty (the SCAN_NOW payload).
            return self::map(get_object_vars($value));
        }

        if (!\is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::sorted(...), $value);
        }

        return self::map($value);
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function map(array $value): object
    {
        // Keys are ASCII in the protocol: byte order is the UTF-16 code unit order RFC 8785 asks for.
        ksort($value, \SORT_STRING);

        return (object) array_map(self::sorted(...), $value);
    }
}
