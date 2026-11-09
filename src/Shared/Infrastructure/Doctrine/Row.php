<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Doctrine;

/**
 * Typed read of a DBAL value (rows and scalar results are mixed): a value of the wrong type is a programming error.
 */
final class Row
{
    public static function string(mixed $value): string
    {
        return \is_string($value) || \is_int($value) || \is_float($value) ? (string) $value : throw new \UnexpectedValueException('A string column was expected.');
    }

    public static function nullableString(mixed $value): ?string
    {
        return null === $value ? null : self::string($value);
    }

    public static function int(mixed $value): int
    {
        return \is_int($value) || (\is_string($value) && is_numeric($value)) ? (int) $value : throw new \UnexpectedValueException('An integer column was expected.');
    }
}
