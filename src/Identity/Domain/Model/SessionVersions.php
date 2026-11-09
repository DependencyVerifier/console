<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

use Doctrine\ORM\Mapping as ORM;

/**
 * Bumping `authVersion` logs every session out (SecurityUser equality); bumping `trustedDeviceVersion` revokes every
 * trusted device cookie (scheb).
 */
#[ORM\Embeddable]
final class SessionVersions
{
    private function __construct(
        #[ORM\Column(name: 'auth_version')]
        private int $authVersion,
        #[ORM\Column(name: 'trusted_device_version')]
        private int $trustedDeviceVersion,
    ) {
    }

    public static function initial(): self
    {
        return new self(1, 1);
    }

    public function revokeAll(): self
    {
        return new self($this->authVersion + 1, $this->trustedDeviceVersion + 1);
    }

    public function authVersion(): int
    {
        return $this->authVersion;
    }

    public function trustedDeviceVersion(): int
    {
        return $this->trustedDeviceVersion;
    }
}
