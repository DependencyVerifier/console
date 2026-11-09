<?php

declare(strict_types=1);

namespace Shared\Application\Authorization;

use Shared\Domain\Authorization\Permission;

/**
 * Instance permissions of the current actor (super admin, `account.login`…), implemented by Identity. Organization's
 * PermissionChecker composes it with the team and project permissions.
 */
interface InstancePermissions
{
    public function isGranted(Permission $permission): bool;
}
