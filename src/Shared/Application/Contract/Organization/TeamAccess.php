<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Organization;

final readonly class TeamAccess
{
    /**
     * @param string|null $role the actor's team role value (team_admin…), null for a super admin outside the team
     */
    public function __construct(public string $teamId, public string $teamName, public ?string $role, public bool $superAdmin)
    {
    }

    /**
     * The fleet of the team (hosts, agents, enrollment) is seen by every team role but TEAM_GUEST, and by the super
     * admin. A guest will see the hosts of its projects once the inventory links them.
     */
    public function seesFleet(): bool
    {
        return $this->superAdmin || (null !== $this->role && 'team_guest' !== $this->role);
    }
}
