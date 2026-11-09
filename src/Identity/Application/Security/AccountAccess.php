<?php

declare(strict_types=1);

namespace Identity\Application\Security;

use Identity\Domain\Model\User;
use Identity\Domain\Model\UserId;
use Identity\Domain\Repository\UserRepository;
use Shared\Application\Authorization\PermissionChecker;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Security\CurrentActor;
use Shared\Domain\Authorization\Permission;

/**
 * Authorization and loading shared by the Identity handlers: self-service gestures belong to the account holder
 * only, administration needs an instance permission. Accounts are loaded locked (FOR UPDATE).
 */
final readonly class AccountAccess
{
    public function __construct(
        private CurrentActor $currentActor,
        private PermissionChecker $permissions,
        private UserRepository $users,
    ) {
    }

    public function assertSelf(UserId $userId): void
    {
        $actor = $this->currentActor->actor();
        if (!$actor->isUser() || !$userId->value->equals($actor->userId())) {
            throw PermissionDenied::for('self');
        }
    }

    public function assertGranted(Permission $permission): void
    {
        if (!$this->permissions->isGranted($permission)) {
            throw PermissionDenied::for($permission->value);
        }
    }

    public function isActor(UserId $userId): bool
    {
        $actor = $this->currentActor->actor();

        return $actor->isUser() && $userId->value->equals($actor->userId());
    }

    public function lock(UserId $userId): User
    {
        return $this->users->lockById($userId) ?? throw ResourceNotFound::of('user');
    }
}
