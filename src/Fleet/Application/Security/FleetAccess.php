<?php

declare(strict_types=1);

namespace Fleet\Application\Security;

use Shared\Application\Authorization\PermissionChecker;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Organization\GetTeamAccess;
use Shared\Application\Contract\Organization\TeamAccess;
use Shared\Domain\Authorization\Permission;

/**
 * Who may see and act on the fleet of a team: a team outside the actor's scope is not found (404, Organization
 * decides), a TEAM_GUEST does not see the fleet (403), every gesture needs its permission (403). The object's real
 * team decides, never the team of the URL.
 */
final readonly class FleetAccess
{
    public function __construct(private QueryBus $queries, private PermissionChecker $permissions)
    {
    }

    public function view(string $teamId): TeamAccess
    {
        $access = $this->queries->ask(new GetTeamAccess($teamId));
        if (!$access->seesFleet()) {
            throw PermissionDenied::for(Permission::HostView->value);
        }

        return $access;
    }

    public function assert(Permission $permission, string $teamId): TeamAccess
    {
        $access = $this->view($teamId);
        if (!$this->permissions->isGranted($permission, $teamId)) {
            throw PermissionDenied::for($permission->value);
        }

        return $access;
    }

    public function allows(Permission $permission, string $teamId): bool
    {
        return $this->permissions->isGranted($permission, $teamId);
    }
}
