<?php

declare(strict_types=1);

namespace Organization\Application\Command\Team\Handler;

use Organization\Application\Command\Team\DeleteTeam;
use Organization\Application\Security\OrganizationAccess;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Repository\InvitationRepository;
use Organization\Domain\Repository\ProjectRepository;
use Organization\Domain\Repository\TeamRepository;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\CommandHandler;
use Shared\Domain\Authorization\Permission;

/**
 * Cascade, unlike a project (decision 16): the team, its projects (environments and project memberships with them)
 * and its memberships are deleted; pending invitations are revoked so that their links stop working. Under the team
 * lock, like every command touching its content.
 */
final readonly class DeleteTeamHandler implements CommandHandler
{
    public function __construct(
        private OrganizationAccess $access,
        private TeamRepository $teams,
        private ProjectRepository $projects,
        private InvitationRepository $invitations,
    ) {
    }

    public function __invoke(DeleteTeam $command): void
    {
        $this->access->assertInstance(Permission::TeamDelete);
        $teamId = TeamId::fromString($command->teamId);
        $team = $this->teams->lockById($teamId) ?? throw ResourceNotFound::of('team');

        foreach ($this->invitations->openOfTeam($teamId) as $invitation) {
            $invitation->revoke();
        }

        foreach ($this->projects->ofTeam($teamId) as $project) {
            $this->projects->remove($project);
        }

        $this->teams->remove($team);
    }
}
