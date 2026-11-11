<?php

declare(strict_types=1);

namespace Fleet\Application\Query\Admin;

use Fleet\Application\Query\View\AgentRow;
use Shared\Application\Bus\Query;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

/**
 * Every agent of the instance (instance.agents.view), with its team.
 *
 * @implements Query<Page<AgentRow>>
 */
final readonly class ListAllAgents implements Query
{
    public function __construct(public PageRequest $page = new PageRequest())
    {
    }
}
