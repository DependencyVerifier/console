<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Organization;

use Shared\Application\Bus\Query;

/**
 * Published query of Organization: the team as the actor sees it (its name, the actor's team role, super admin or
 * not). Not found (404) for a team the actor is not a member of, unless super admin.
 *
 * @implements Query<TeamAccess>
 */
final readonly class GetTeamAccess implements Query
{
    public function __construct(public string $teamId)
    {
    }
}
