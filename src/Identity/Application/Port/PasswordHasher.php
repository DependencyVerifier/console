<?php

declare(strict_types=1);

namespace Identity\Application\Port;

/**
 * Argon2id, the same hasher the firewall uses to check the password.
 */
interface PasswordHasher
{
    public function hash(#[\SensitiveParameter] string $password): string;
}
