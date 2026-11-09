<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

use Doctrine\ORM\Mapping as ORM;

/**
 * `none` (notification-only account never activated) or `password(hash)`. The hash column is only nullable in the
 * database; the invariant "a password kind has a hash" is checked on every read.
 */
#[ORM\Embeddable]
final class Credentials
{
    private const string NONE = 'none';
    private const string PASSWORD = 'password';

    private function __construct(
        #[ORM\Column(name: 'credentials_kind', length: 16)]
        private string $kind,
        #[ORM\Column(name: 'password_hash', length: 255, nullable: true)]
        private ?string $passwordHash,
    ) {
    }

    public static function none(): self
    {
        return new self(self::NONE, null);
    }

    public static function password(string $hash): self
    {
        return new self(self::PASSWORD, $hash);
    }

    public function hasPassword(): bool
    {
        return self::PASSWORD === $this->kind && null !== $this->passwordHash;
    }

    public function passwordHash(): string
    {
        if (!$this->hasPassword() || null === $this->passwordHash) {
            throw new \LogicException('This account has no password.');
        }

        return $this->passwordHash;
    }
}
