<?php

declare(strict_types=1);

namespace Organization\Application\Command\Project\Handler;

use Organization\Application\Command\Project\CreateProject;
use Organization\Application\Security\OrganizationAccess;
use Organization\Domain\Model\Project;
use Organization\Domain\Model\ProjectId;
use Organization\Domain\Model\QuotaName;
use Organization\Domain\Model\QuotaPolicy;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Repository\ProjectRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Text\SlugGenerator;
use Shared\Domain\Authorization\Permission;

final readonly class CreateProjectHandler implements CommandHandler
{
    public function __construct(
        private OrganizationAccess $access,
        private ProjectRepository $projects,
        private SlugGenerator $slugs,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CreateProject $command): void
    {
        $teamId = TeamId::fromString($command->teamId);
        $team = $this->access->lockTeam($teamId);
        $this->access->assert(Permission::ProjectCreate, $teamId);
        QuotaPolicy::assertAllows($team, QuotaName::MaxProjects, $this->projects->countOfTeam($teamId));

        $slug = $this->slugs->unique($command->name, fn (string $slug): bool => $this->projects->slugExists($teamId, $slug));
        $this->projects->add(Project::create(
            ProjectId::fromString($command->projectId),
            $teamId,
            $command->name,
            $slug,
            $this->access->actorId(),
            $this->access->teamRole($teamId),
            $this->clock->now(),
        ));
    }
}
