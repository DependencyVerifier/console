<?php

declare(strict_types=1);

namespace Organization\Application\Query\Contract\Handler;

use Organization\Application\Security\ActorPermissions;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Repository\TeamRepository;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Organization\GetVisibleProjects;
use Shared\Application\Contract\Organization\VisibleProjects;

final readonly class GetVisibleProjectsHandler implements QueryHandler
{
    public function __construct(private ActorPermissions $permissions, private TeamRepository $teams)
    {
    }

    public function __invoke(GetVisibleProjects $query): VisibleProjects
    {
        $permissions = $this->permissions->current();
        if (null === $this->teams->find(TeamId::fromString($query->teamId)) || !$permissions->isTeamMember($query->teamId)) {
            throw ResourceNotFound::of('team');
        }

        return $permissions->seesAllProjectsOf($query->teamId) ? new VisibleProjects(true) : new VisibleProjects(false, $permissions->projectIdsOf($query->teamId));
    }
}
