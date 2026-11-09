<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Security;

use Identity\Domain\Model\UserId;
use Identity\Domain\Repository\UserRepository;
use Shared\Application\Authorization\PermissionChecker;
use Shared\Domain\Authorization\Permission;
use Shared\Domain\Authorization\PermissionScope;

/**
 * Instance permissions, read from the account (never from the session): the super admin holds every permission,
 * the others only their instance permissions. Team and project scopes are denied until Organization provides its
 * resolver (fail-closed).
 */
final readonly class InstancePermissionChecker implements PermissionChecker
{
    public function __construct(private AuthenticatedCurrentActor $currentActor, private UserRepository $users)
    {
    }

    public function isGranted(Permission $permission, ?string $teamId = null, ?string $projectId = null): bool
    {
        if (!$this->currentActor->isFullyAuthenticated()) {
            return false;
        }

        $user = $this->users->find(UserId::of($this->currentActor->actor()->userId()));
        if (null === $user || !$user->canLogIn()) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return PermissionScope::Instance === $permission->scope() && $user->privileges()->has($permission);
    }
}
