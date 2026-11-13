<?php

declare(strict_types=1);

namespace Identity\Application\Query\User\Handler;

use Identity\Application\Query\User\GetUserProfile;
use Identity\Application\Query\User\PasskeySummary;
use Identity\Application\Query\User\UserProfile;
use Identity\Domain\Model\PasskeyCredential;
use Identity\Domain\Model\UserId;
use Identity\Domain\Repository\UserRepository;
use Shared\Application\Authorization\PermissionChecker;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Security\CurrentActor;
use Shared\Domain\Authorization\Permission;

/**
 * The account holder or an `instance.users.manage` holder; anyone else gets a 404 (no existence leak).
 */
final readonly class GetUserProfileHandler implements QueryHandler
{
    public function __construct(private UserRepository $users, private CurrentActor $currentActor, private PermissionChecker $permissions)
    {
    }

    public function __invoke(GetUserProfile $query): UserProfile
    {
        $userId = UserId::fromString($query->userId);
        $actor = $this->currentActor->actor();
        $isSelf = $actor->isUser() && $userId->value->equals($actor->userId());
        $user = $this->users->find($userId);
        if (null === $user || (!$isSelf && !$this->permissions->isGranted(Permission::InstanceUsersManage))) {
            throw ResourceNotFound::of('user');
        }

        return new UserProfile(
            $user->id()->toString(),
            $user->email()->value(),
            $user->displayName(),
            $user->isSuperAdmin(),
            $user->status()->isActive(),
            $user->canLogIn(),
            $user->secondFactor()->state()->name(),
            $user->remainingBackupCodes(),
            $user->createdAt(),
            array_map(static fn (PasskeyCredential $p): PasskeySummary => new PasskeySummary($p->id()->toRfc4122(), $p->name(), $p->createdAt(), $p->lastUsedAt()), $user->passkeys()),
        );
    }
}
