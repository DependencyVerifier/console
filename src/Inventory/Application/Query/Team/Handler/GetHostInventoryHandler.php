<?php

declare(strict_types=1);

namespace Inventory\Application\Query\Team\Handler;

use Inventory\Application\Query\Team\GetHostInventory;
use Inventory\Application\Query\View\HostView;
use Shared\Application\Bus\QueryHandler;

final readonly class GetHostInventoryHandler implements QueryHandler
{
    public function __construct(private InventoryVisibility $visibility)
    {
    }

    public function __invoke(GetHostInventory $query): HostView
    {
        return $this->visibility->host($query->teamId, $query->hostId);
    }
}
