<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Inventory;

use Shared\Application\Bus\Query;

/**
 * Published query of Inventory: the teams with at least one inventoried host.
 *
 * @implements Query<list<string>>
 */
final readonly class ListInventoriedTeams implements Query
{
}
