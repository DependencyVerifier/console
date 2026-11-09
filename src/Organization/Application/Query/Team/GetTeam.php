<?php

declare(strict_types=1);

namespace Organization\Application\Query\Team;

use Organization\Application\Query\View\TeamView;
use Shared\Application\Bus\Query;

/**
 * @implements Query<TeamView>
 */
final readonly class GetTeam implements Query
{
    public function __construct(public string $teamId)
    {
    }
}
