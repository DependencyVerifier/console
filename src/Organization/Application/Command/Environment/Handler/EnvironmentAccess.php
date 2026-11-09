<?php

declare(strict_types=1);

namespace Organization\Application\Command\Environment\Handler;

use Organization\Application\Security\OrganizationAccess;
use Organization\Domain\Model\EnvironmentId;
use Organization\Domain\Model\EnvironmentNotFound;
use Organization\Domain\Model\Project;
use Organization\Domain\Model\ProjectId;
use Organization\Domain\Model\Team;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Domain\Authorization\Permission;

/**
 * The project of an environment, its team locked, `project.environments.manage` checked on that real project.
 */
final readonly class EnvironmentAccess
{
    public function __construct(private OrganizationAccess $access)
    {
    }

    /**
     * @return array{Project, Team}
     */
    public function manage(string $projectId): array
    {
        $id = ProjectId::fromString($projectId);
        $project = $this->access->project($id);
        $team = $this->access->lockTeam($project->teamId());
        $this->access->assert(Permission::ProjectEnvironmentsManage, $project->teamId(), $id);

        return [$project, $team];
    }

    public function assertBelongs(Project $project, string $environmentId): EnvironmentId
    {
        $id = EnvironmentId::fromString($environmentId);
        try {
            $project->environment($id);
        } catch (EnvironmentNotFound) {
            throw ResourceNotFound::of('environment');
        }

        return $id;
    }
}
