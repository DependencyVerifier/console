<?php

declare(strict_types=1);

namespace Inventory\Application\Query\Team;

use Inventory\Application\Query\View\HostView;
use Shared\Application\Bus\Query;

/**
 * @implements Query<HostView>
 */
final readonly class GetHostInventory implements Query
{
    public function __construct(public string $teamId, public string $hostId)
    {
    }
}
