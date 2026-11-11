<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Organization;

use Shared\Application\Bus\Query;

/**
 * Published query of Organization: the limit of one quota of a team (null: unlimited), for the contexts that enforce
 * it on their own objects (Fleet: max_agents, max_reusable_enrollment_tokens, min_scan_interval_minutes). The caller
 * already decided the actor may act on the team.
 *
 * @implements Query<int|null>
 */
final readonly class GetTeamQuota implements Query
{
    public function __construct(public string $teamId, public string $quota)
    {
    }
}
