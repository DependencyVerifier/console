<?php

declare(strict_types=1);

namespace Organization\Domain\Repository;

use Organization\Domain\Model\Project;
use Organization\Domain\Model\ProjectId;
use Organization\Domain\Model\TeamId;
use Symfony\Component\Uid\Uuid;

interface ProjectRepository
{
    public function find(ProjectId $id): ?Project;

    public function add(Project $project): void;

    public function remove(Project $project): void;

    public function countOfTeam(TeamId $teamId): int;

    public function slugExists(TeamId $teamId, string $slug): bool;

    /**
     * @return list<Project>
     */
    public function ofTeamWithMember(TeamId $teamId, Uuid $userId): array;

    /**
     * @return list<Project>
     */
    public function ofTeam(TeamId $teamId): array;
}
