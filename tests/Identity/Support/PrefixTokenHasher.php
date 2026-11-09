<?php

declare(strict_types=1);

namespace Tests\Identity\Support;

use Shared\Application\Crypto\TokenHasher;

final class PrefixTokenHasher implements TokenHasher
{
    public function hash(string $token): string
    {
        return 'h:'.$token;
    }

    public function verify(string $token, string $hash): bool
    {
        return 'h:'.$token === $hash;
    }
}
