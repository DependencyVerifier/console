<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Organization;

use Shared\Application\Bus\Query;

/**
 * Published query of Organization: the names of these teams (unknown ids left out), for the instance views of the
 * other contexts. The caller already decided the actor may see them.
 *
 * @implements Query<array<string, string>>
 */
final readonly class FindTeamNames implements Query
{
    /**
     * @param list<string> $teamIds
     */
    public function __construct(public array $teamIds)
    {
    }
}
