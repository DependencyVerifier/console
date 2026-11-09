<?php

declare(strict_types=1);

namespace Organization\Application\Command\Project\Handler;

use Organization\Application\Command\Project\DeleteProject;
use Organization\Application\Security\OrganizationAccess;
use Organization\Domain\Model\ProjectId;
use Organization\Domain\Model\ProjectNotEmpty;
use Organization\Domain\Repository\InvitationRepository;
use Organization\Domain\Repository\ProjectRepository;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Content\ProjectContentProbe;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Only an empty project: no environment and no content declared by another context (ProjectContentProbe). Its
 * pending invitations are revoked in the same transaction, under the team lock.
 */
final readonly class DeleteProjectHandler implements CommandHandler
{
    /**
     * @param iterable<ProjectContentProbe> $probes
     */
    public function __construct(
        private OrganizationAccess $access,
        private ProjectRepository $projects,
        private InvitationRepository $invitations,
        #[AutowireIterator(ProjectContentProbe::class)]
        private iterable $probes,
    ) {
    }

    public function __invoke(DeleteProject $command): void
    {
        $projectId = ProjectId::fromString($command->projectId);
        $project = $this->access->project($projectId);
        $this->access->lockTeam($project->teamId());
        $this->access->assert(Permission::ProjectDelete, $project->teamId(), $projectId);
        foreach ($this->probes as $probe) {
            if ($probe->projectHasContent($command->projectId)) {
                throw new ProjectNotEmpty();
            }
        }

        $project->assertDeletable();
        foreach ($this->invitations->openOfProject($projectId) as $invitation) {
            $invitation->revoke();
        }

        $this->projects->remove($project);
    }
}
