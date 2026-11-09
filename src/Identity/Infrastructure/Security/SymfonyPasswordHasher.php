<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Security;

use Identity\Application\Port\PasswordHasher;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

final readonly class SymfonyPasswordHasher implements PasswordHasher
{
    public function __construct(private PasswordHasherFactoryInterface $hashers)
    {
    }

    public function hash(#[\SensitiveParameter] string $password): string
    {
        return $this->hashers->getPasswordHasher(SecurityUser::class)->hash($password);
    }
}
