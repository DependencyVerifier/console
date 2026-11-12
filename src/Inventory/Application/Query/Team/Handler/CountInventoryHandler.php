<?php

declare(strict_types=1);

namespace Inventory\Application\Query\Team\Handler;

use Inventory\Application\Query\InventoryReadModel;
use Inventory\Application\Query\Team\CountInventory;
use Inventory\Application\Query\View\InventoryCounts;
use Inventory\Application\Security\InventoryAccess;
use Shared\Application\Bus\QueryHandler;

/**
 * A member counts the whole inventory; a TEAM_GUEST the applications of its projects only.
 */
final readonly class CountInventoryHandler implements QueryHandler
{
    public function __construct(private InventoryAccess $access, private InventoryReadModel $read)
    {
    }

    public function __invoke(CountInventory $query): InventoryCounts
    {
        $scope = $this->access->scope($query->teamId);
        $counts = $this->read->counts($query->teamId, $scope->visibleEnvironments);

        return $scope->seesAll()
            ? new InventoryCounts($counts['applications'], $counts['unattached'], $counts['images'])
            : new InventoryCounts($counts['applications'], null, null);
    }
}
