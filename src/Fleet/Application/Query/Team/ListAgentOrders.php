<?php

declare(strict_types=1);

namespace Fleet\Application\Query\Team;

use Fleet\Application\Query\View\OrderRow;
use Shared\Application\Bus\Query;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

/**
 * @implements Query<Page<OrderRow>>
 */
final readonly class ListAgentOrders implements Query
{
    public function __construct(public string $agentId, public PageRequest $page = new PageRequest())
    {
    }
}
