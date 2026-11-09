<?php

declare(strict_types=1);

namespace Tests\Identity\Support;

use Identity\Domain\Security\CodeHashMatcher;

/**
 * A "hash" is `h:` + the code.
 */
final class PrefixMatcher implements CodeHashMatcher
{
    public static function hash(string $code): string
    {
        return 'h:'.$code;
    }

    public function matches(string $code, string $hash): bool
    {
        return self::hash($code) === $hash;
    }
}
