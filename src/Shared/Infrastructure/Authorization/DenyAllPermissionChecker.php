<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Authorization;

use Shared\Application\Authorization\PermissionChecker;
use Shared\Domain\Authorization\Permission;

/**
 * Fail-closed default until a context provides the real resolver.
 */
final class DenyAllPermissionChecker implements PermissionChecker
{
    public function isGranted(Permission $permission, ?string $teamId = null, ?string $projectId = null): bool
    {
        return false;
    }
}
