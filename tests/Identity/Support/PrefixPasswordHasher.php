<?php

declare(strict_types=1);

namespace Tests\Identity\Support;

use Identity\Application\Port\PasswordHasher;

final class PrefixPasswordHasher implements PasswordHasher
{
    public function hash(string $password): string
    {
        return 'hashed:'.$password;
    }
}
