<?php

declare(strict_types=1);

namespace Organization\Application\Command\Member\Handler;

use Organization\Application\Security\OrganizationAccess;
use Organization\Domain\Repository\ProjectRepository;
use Organization\Domain\Repository\TeamRepository;
use Shared\Application\Audit\ActorType;
use Shared\Application\Security\CurrentActor;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;

/**
 * Suspension / restoration of every membership of an account: the system actor (reaction to AccountDeactivated /
 * AccountReactivated) or an `instance.users.manage` holder ("Re-sync memberships"). Teams locked in id order.
 */
final readonly class MembershipCascade
{
    public function __construct(
        private CurrentActor $currentActor,
        private OrganizationAccess $access,
        private TeamRepository $teams,
        private ProjectRepository $projects,
    ) {
    }

    public function apply(string $userId, bool $suspend): void
    {
        if (ActorType::System !== $this->currentActor->actor()->type) {
            $this->access->assertInstance(Permission::InstanceUsersManage);
        }

        $user = Uuid::fromString($userId);
        foreach ($this->teams->idsOfMember($user) as $teamId) {
            $team = $this->teams->lockById($teamId);
            if (null === $team) {
                continue;
            }

            $suspend ? $team->suspendMember($user) : $team->restoreMember($user);
            foreach ($this->projects->ofTeamWithMember($teamId, $user) as $project) {
                $suspend ? $project->suspendMember($user) : $project->restoreMember($user);
            }
        }
    }
}
