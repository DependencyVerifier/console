<?php

declare(strict_types=1);

namespace Organization\Infrastructure\Security;

use Organization\Application\Security\ActorPermissions;
use Shared\Application\Authorization\InstancePermissions;
use Shared\Application\Authorization\PermissionChecker;
use Shared\Domain\Authorization\Permission;
use Shared\Domain\Authorization\PermissionScope;

/**
 * The PermissionChecker of the instance: instance permissions from Identity (super admin, account.login), team and
 * project permissions from the memberships (EffectivePermissions).
 */
final readonly class OrganizationPermissionChecker implements PermissionChecker
{
    public function __construct(private InstancePermissions $instance, private ActorPermissions $permissions)
    {
    }

    public function isGranted(Permission $permission, ?string $teamId = null, ?string $projectId = null): bool
    {
        return PermissionScope::Instance === $permission->scope()
            ? $this->instance->isGranted($permission)
            : $this->permissions->current()->allows($permission, $teamId, $projectId);
    }
}
