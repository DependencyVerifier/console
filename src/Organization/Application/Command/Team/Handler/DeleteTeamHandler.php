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
use Shared\Application\Content\TeamContentEraser;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Cascade, unlike a project (decision 16): the team, its projects (environments and project memberships with them)
 * and its memberships are deleted; pending invitations are revoked so that their links stop working; the other
 * contexts erase what they hold for it (TeamContentEraser: Fleet's agents, hosts, tokens). Under the team lock, like
 * every command touching its content.
 */
final readonly class DeleteTeamHandler implements CommandHandler
{
    /**
     * @param iterable<TeamContentEraser> $erasers
     */
    public function __construct(
        private OrganizationAccess $access,
        private TeamRepository $teams,
        private ProjectRepository $projects,
        private InvitationRepository $invitations,
        #[AutowireIterator(TeamContentEraser::class)]
        private iterable $erasers,
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

        foreach ($this->erasers as $eraser) {
            $eraser->eraseTeam($command->teamId);
        }

        foreach ($this->projects->ofTeam($teamId) as $project) {
            $this->projects->remove($project);
        }

        $this->teams->remove($team);
    }
}
