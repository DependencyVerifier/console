<?php

declare(strict_types=1);

namespace Inventory\Application\Query\Team;

use Inventory\Application\Query\View\InventoryCounts;
use Shared\Application\Bus\Query;

/**
 * The key figures of a team's inventory on its overview, as far as the actor sees it.
 *
 * @implements Query<InventoryCounts>
 */
final readonly class CountInventory implements Query
{
    public function __construct(public string $teamId)
    {
    }
}
