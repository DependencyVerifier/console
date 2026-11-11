<?php

declare(strict_types=1);

namespace Organization\Application\Query\Contract\Handler;

use Organization\Application\Security\ActorPermissions;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Repository\TeamRepository;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Organization\GetTeamAccess;
use Shared\Application\Contract\Organization\TeamAccess;

final readonly class GetTeamAccessHandler implements QueryHandler
{
    public function __construct(private ActorPermissions $permissions, private TeamRepository $teams)
    {
    }

    public function __invoke(GetTeamAccess $query): TeamAccess
    {
        $permissions = $this->permissions->current();
        $team = $this->teams->find(TeamId::fromString($query->teamId));
        if (null === $team || !$permissions->isTeamMember($query->teamId)) {
            throw ResourceNotFound::of('team');
        }

        return new TeamAccess($query->teamId, $team->name(), $permissions->teamRole($query->teamId)?->value, $permissions->isSuperAdmin());
    }
}
