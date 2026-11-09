<?php

declare(strict_types=1);

namespace Identity\Application\Query\User;

final readonly class UserSummary
{
    public function __construct(
        public string $id,
        public string $email,
        public bool $superAdmin,
        public bool $active,
        public bool $canLogIn,
        public bool $totpActive,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
