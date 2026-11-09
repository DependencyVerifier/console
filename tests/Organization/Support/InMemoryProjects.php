<?php

declare(strict_types=1);

namespace Tests\Organization\Support;

use Organization\Domain\Model\Project;
use Organization\Domain\Model\ProjectId;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Repository\ProjectRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryProjects implements ProjectRepository
{
    /** @var array<string, Project> */
    public array $projects = [];

    public function __construct(Project ...$projects)
    {
        foreach ($projects as $project) {
            $this->add($project);
        }
    }

    public function find(ProjectId $id): ?Project
    {
        return $this->projects[$id->toString()] ?? null;
    }

    public function add(Project $project): void
    {
        $this->projects[$project->id()->toString()] = $project;
    }

    public function remove(Project $project): void
    {
        unset($this->projects[$project->id()->toString()]);
    }

    public function countOfTeam(TeamId $teamId): int
    {
        return \count(array_filter($this->projects, static fn (Project $p): bool => $p->teamId()->equals($teamId)));
    }

    public function slugExists(TeamId $teamId, string $slug): bool
    {
        return array_any($this->projects, static fn (Project $p): bool => $p->teamId()->equals($teamId) && $p->slug() === $slug);
    }

    public function ofTeamWithMember(TeamId $teamId, Uuid $userId): array
    {
        return array_values(array_filter($this->projects, static fn (Project $p): bool => $p->teamId()->equals($teamId) && $p->isMember($userId)));
    }
}
