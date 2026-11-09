<?php

declare(strict_types=1);

namespace Tests\Identity\Support;

use Identity\Domain\Security\CodeHashMatcher;

/**
 * Matches the hashes of PrefixTokenHasher (`h:` + code).
 */
final class TokenMatcher implements CodeHashMatcher
{
    public function matches(string $code, string $hash): bool
    {
        return new PrefixTokenHasher()->verify($code, $hash);
    }
}
