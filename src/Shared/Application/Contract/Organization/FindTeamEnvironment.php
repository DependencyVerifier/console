<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Organization;

use Shared\Application\Bus\Query;

/**
 * Published query of Organization: an environment, only if it belongs to a project of that team (null otherwise).
 *
 * @implements Query<TeamEnvironment|null>
 */
final readonly class FindTeamEnvironment implements Query
{
    public function __construct(public string $teamId, public string $environmentId)
    {
    }
}
