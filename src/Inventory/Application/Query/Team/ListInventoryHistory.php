<?php

declare(strict_types=1);

namespace Inventory\Application\Query\Team;

use Inventory\Application\Query\View\HistoryRow;
use Shared\Application\Bus\Query;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

/**
 * The inventories that saw a host (its facts and OS packages) or an application, the latest first, within the
 * retention of the team.
 *
 * @implements Query<Page<HistoryRow>>
 */
final readonly class ListInventoryHistory implements Query
{
    public const string APPLICATION = 'application';
    public const string HOST = 'host';

    public function __construct(public string $teamId, public string $of, public string $id, public PageRequest $page = new PageRequest())
    {
    }
}
