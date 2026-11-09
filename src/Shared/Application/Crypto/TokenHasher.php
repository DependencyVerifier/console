<?php

declare(strict_types=1);

namespace Shared\Application\Crypto;

/**
 * Hashes secrets that are never read back (link tokens, codes): keyed HMAC, so a database leak alone is not enough
 * to attack them offline.
 */
interface TokenHasher
{
    public function hash(#[\SensitiveParameter] string $token): string;

    public function verify(#[\SensitiveParameter] string $token, string $hash): bool;
}
