<?php

declare(strict_types=1);

namespace Shared\Application\Authorization;

use Shared\Domain\Authorization\Permission;

/**
 * Does the current actor hold a permission on an instance, team or project scope? Implemented by Organization
 * (PermissionResolver); denies everything until then (fail-closed).
 */
interface PermissionChecker
{
    public function isGranted(Permission $permission, ?string $teamId = null, ?string $projectId = null): bool;
}
