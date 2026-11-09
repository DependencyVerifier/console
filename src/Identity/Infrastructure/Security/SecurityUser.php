<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Security;

use Identity\Domain\Model\User;
use Scheb\TwoFactorBundle\Model\TrustedDeviceInterface;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function Symfony\Component\String\u;

/**
 * Snapshot of an account for the firewall. Equality covers id, authVersion and the super admin flag: bumping the
 * version logs every session out (native Symfony invalidation). Only the id, email, authVersion and super admin flag
 * are serialized into the session: never the password hash, never the TOTP secret.
 */
final class SecurityUser implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface, TrustedDeviceInterface
{
    private function __construct(
        public readonly string $id,
        public readonly string $email,
        private ?string $passwordHash,
        public readonly int $authVersion,
        private int $trustedDeviceVersion,
        public readonly bool $superAdmin,
        private bool $totpActive,
        private bool $canLogIn,
        private string $displayName,
    ) {
    }

    public static function of(User $user): self
    {
        return new self(
            $user->id()->toString(),
            $user->email()->value(),
            $user->credentials()->hasPassword() ? $user->credentials()->passwordHash() : null,
            $user->sessionVersions()->authVersion(),
            $user->sessionVersions()->trustedDeviceVersion(),
            $user->isSuperAdmin(),
            $user->secondFactor()->state()->isTotpActive(),
            $user->canLogIn(),
            $user->displayName(),
        );
    }

    /**
     * The display name, else the local part of the email. Rebuilt from the database at every request (the session
     * keeps the id, the email and the versions only).
     */
    public function label(): string
    {
        return '' !== $this->displayName ? $this->displayName : u($this->email)->before('@')->toString();
    }

    public function getUserIdentifier(): string
    {
        return '' !== $this->email ? $this->email : throw new \LogicException('Empty identifier.');
    }

    public function getRoles(): array
    {
        return $this->superAdmin ? ['ROLE_USER', 'ROLE_SUPER_ADMIN'] : ['ROLE_USER'];
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    public function getTrustedTokenVersion(): int
    {
        return $this->trustedDeviceVersion;
    }

    public function isTotpActive(): bool
    {
        return $this->totpActive;
    }

    public function canLogIn(): bool
    {
        return $this->canLogIn;
    }

    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self
            && $user->id === $this->id
            && $user->authVersion === $this->authVersion
            && $user->superAdmin === $this->superAdmin;
    }

    /**
     * @return array{id: string, email: string, authVersion: int, superAdmin: bool}
     */
    public function __serialize(): array
    {
        return ['id' => $this->id, 'email' => $this->email, 'authVersion' => $this->authVersion, 'superAdmin' => $this->superAdmin];
    }

    /**
     * @param array{id: string, email: string, authVersion: int, superAdmin: bool} $data
     */
    public function __unserialize(array $data): void
    {
        [$this->id, $this->email, $this->authVersion, $this->superAdmin] = [$data['id'], $data['email'], $data['authVersion'], $data['superAdmin']];
        $this->passwordHash = null;
        $this->trustedDeviceVersion = 0;
        $this->totpActive = false;
        $this->displayName = '';
        $this->canLogIn = false;
    }
}
