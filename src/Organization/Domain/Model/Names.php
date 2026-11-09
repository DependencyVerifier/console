<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

use function Symfony\Component\String\u;

/**
 * Display names (trimmed, 1 to 120 characters). Names are display only: ids identify, slugs decorate URLs.
 */
final class Names
{
    public static function team(string $name): string
    {
        return self::checked($name, 120);
    }

    public static function project(string $name): string
    {
        return self::checked($name, 120);
    }

    public static function environment(string $name): string
    {
        return self::checked($name, 60);
    }

    private static function checked(string $name, int $max): string
    {
        $trimmed = u($name)->trim();
        if ($trimmed->isEmpty() || $trimmed->length() > $max) {
            throw new InvalidName();
        }

        return $trimmed->toString();
    }
}
