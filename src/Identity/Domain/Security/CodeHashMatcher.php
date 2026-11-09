<?php

declare(strict_types=1);

namespace Identity\Domain\Security;

/**
 * Compares a submitted code with its stored hash (keyed HMAC, constant time). Implemented in Infrastructure.
 */
interface CodeHashMatcher
{
    public function matches(#[\SensitiveParameter] string $code, string $hash): bool;
}
