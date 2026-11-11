<?php

declare(strict_types=1);

namespace Tests\Fleet\Support;

use Shared\Application\Authorization\InstancePermissions;
use Shared\Application\Authorization\PermissionChecker;
use Shared\Domain\Authorization\Permission;

/**
 * Grants the listed permissions on the listed teams (and the listed instance permissions); records nothing else.
 */
final class GrantedPermissions implements PermissionChecker, InstancePermissions
{
    /** @var array<string, list<Permission>> teamId => permissions */
    public array $team = [];

    /** @var list<Permission> */
    public array $instance = [];

    public function isGranted(Permission $permission, ?string $teamId = null, ?string $projectId = null): bool
    {
        return null === $teamId ? \in_array($permission, $this->instance, true) : \in_array($permission, $this->team[$teamId] ?? [], true);
    }
}
