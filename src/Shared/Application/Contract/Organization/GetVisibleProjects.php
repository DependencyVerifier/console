<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Organization;

use Shared\Application\Bus\Query;

/**
 * Published query of Organization: which projects of a team the actor sees (every one for a member, its own ones
 * for a TEAM_GUEST). Not found (404) for a team the actor is not a member of, unless super admin.
 *
 * @implements Query<VisibleProjects>
 */
final readonly class GetVisibleProjects implements Query
{
    public function __construct(public string $teamId)
    {
    }
}
