<?php

declare(strict_types=1);

namespace Organization\Application\Command\Member\Handler;

use Organization\Application\Command\Member\RemoveTeamMember;
use Organization\Application\Security\OrganizationAccess;
use Organization\Application\Security\RoleAuthority;
use Organization\Domain\Model\GrantChange;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Repository\ProjectRepository;
use Shared\Application\Bus\CommandHandler;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;

/**
 * Removes the team membership and every project membership of the team, under the team lock (one transaction).
 */
final readonly class RemoveTeamMemberHandler implements CommandHandler
{
    public function __construct(
        private OrganizationAccess $access,
        private RoleAuthority $roles,
        private ProjectRepository $projects,
        private MemberGrants $grants,
    ) {
    }

    public function __invoke(RemoveTeamMember $command): void
    {
        $teamId = TeamId::fromString($command->teamId);
        $team = $this->access->lockTeam($teamId);
        $this->access->assert(Permission::TeamMembersManage, $teamId);
        $userId = Uuid::fromString($command->userId);
        $role = $team->membershipOf($userId)->role();
        $this->roles->assertTeamRole($teamId, $role);
        $team->removeMember($userId);
        foreach ($this->projects->ofTeamWithMember($teamId, $userId) as $project) {
            $project->removeMember($userId);
        }

        $this->grants->record($teamId, null, $userId, GrantChange::Removed, $role->value);
    }
}
