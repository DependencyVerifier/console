<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Shared\Domain\Authorization\Permission;
use Shared\Domain\Authorization\PermissionScope;

/**
 * Instance-level privileges of an account: the super admin flag (single source of ROLE_SUPER_ADMIN) and instance
 * permissions such as `account.login` (a notification-only account simply lacks it, decision 16).
 */
#[ORM\Embeddable]
final class Privileges
{
    /**
     * @param list<string> $instancePermissions
     */
    private function __construct(
        #[ORM\Column(name: 'super_admin')]
        private bool $superAdmin,
        #[ORM\Column(name: 'instance_permissions', type: 'json')]
        private array $instancePermissions,
    ) {
    }

    public static function none(): self
    {
        return new self(false, []);
    }

    public function isSuperAdmin(): bool
    {
        return $this->superAdmin;
    }

    public function has(Permission $permission): bool
    {
        return \in_array($permission->value, $this->instancePermissions, true);
    }

    public function withSuperAdmin(bool $superAdmin): self
    {
        return new self($superAdmin, $this->instancePermissions);
    }

    public function granting(Permission $permission): self
    {
        if (PermissionScope::Instance !== $permission->scope()) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an instance permission.', $permission->value));
        }

        if ($this->has($permission)) {
            return $this;
        }

        return new self($this->superAdmin, [...$this->instancePermissions, $permission->value]);
    }
}
