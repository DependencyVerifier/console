<?php

declare(strict_types=1);

namespace Identity\Application\Query\User;

/**
 * Read model of one account (never a hash, a secret or a code).
 */
final readonly class UserProfile
{
    public function __construct(
        public string $id,
        public string $email,
        public string $displayName,
        public bool $superAdmin,
        public bool $active,
        public bool $canLogIn,
        public string $secondFactor,
        public int $remainingBackupCodes,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public function isTotpActive(): bool
    {
        return 'active_totp' === $this->secondFactor;
    }

    public function isTotpPending(): bool
    {
        return 'pending_totp' === $this->secondFactor;
    }
}
