<?php

declare(strict_types=1);

namespace Fleet\Application\Query\Team;

use Fleet\Application\Query\View\FleetView;
use Shared\Application\Bus\Query;

/**
 * @implements Query<FleetView>
 */
final readonly class GetTeamFleet implements Query
{
    public function __construct(public string $teamId)
    {
    }
}
