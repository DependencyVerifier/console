<?php

declare(strict_types=1);

namespace Fleet\Application\Query\Team;

use Fleet\Application\Query\View\AgentRow;
use Shared\Application\Bus\Query;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

/**
 * @implements Query<Page<AgentRow>>
 */
final readonly class ListTeamAgents implements Query
{
    public function __construct(public string $teamId, public PageRequest $page = new PageRequest())
    {
    }
}
