<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Organization;

use Shared\Application\Bus\Query;

/**
 * Published query of Organization: every environment of the team's projects, by project then environment name (the
 * default environment picker of an enrollment token). The caller already decided the actor may act on the team.
 *
 * @implements Query<list<TeamEnvironment>>
 */
final readonly class ListTeamEnvironments implements Query
{
    public function __construct(public string $teamId)
    {
    }
}
