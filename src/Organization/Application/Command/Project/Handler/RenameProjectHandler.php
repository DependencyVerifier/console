<?php

declare(strict_types=1);

namespace Organization\Application\Command\Project\Handler;

use Organization\Application\Command\Project\RenameProject;
use Organization\Application\Security\OrganizationAccess;
use Organization\Domain\Model\ProjectId;
use Shared\Application\Bus\CommandHandler;
use Shared\Domain\Authorization\Permission;

final readonly class RenameProjectHandler implements CommandHandler
{
    public function __construct(private OrganizationAccess $access)
    {
    }

    public function __invoke(RenameProject $command): void
    {
        $projectId = ProjectId::fromString($command->projectId);
        $project = $this->access->project($projectId);
        $this->access->lockTeam($project->teamId());
        $this->access->assert(Permission::ProjectEdit, $project->teamId(), $projectId);
        $project->rename($command->name);
    }
}
