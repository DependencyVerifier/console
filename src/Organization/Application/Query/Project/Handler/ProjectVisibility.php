<?php

declare(strict_types=1);

namespace Organization\Application\Query\Project\Handler;

use Organization\Application\Security\ActorPermissions;
use Organization\Domain\Model\ProjectId;
use Organization\Domain\Repository\ProjectRepository;
use Shared\Application\Authorization\ResourceNotFound;

final readonly class ProjectVisibility
{
    public function __construct(private ActorPermissions $permissions, private ProjectRepository $projects)
    {
    }

    public function assertVisible(string $projectId): void
    {
        $project = $this->projects->find(ProjectId::fromString($projectId));
        if (null === $project || !$this->permissions->current()->seesProject($project->teamId()->toString(), $projectId)) {
            throw ResourceNotFound::of('project');
        }
    }
}
