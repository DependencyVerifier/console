<?php

declare(strict_types=1);

namespace Organization\Application\Query\Team;

use Organization\Application\Query\View\TeamSummary;
use Shared\Application\Bus\Query;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

/**
 * The teams of the actor (every team for the super admin).
 *
 * @implements Query<Page<TeamSummary>>
 */
final readonly class ListTeams implements Query
{
    public function __construct(public PageRequest $page = new PageRequest(limit: PageRequest::MAX_LIMIT))
    {
    }
}
