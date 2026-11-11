<?php

declare(strict_types=1);

namespace Inventory\UI\Frame;

use Shared\Application\Audit\AuditAction;
use Shared\UI\Action\PageRefreshMap;

/**
 * Attaching or detaching an application moves it between the tables of the page and changes its header: the page is
 * refreshed, the message goes through the flash bag.
 */
final class InventoryPageRefresh implements PageRefreshMap
{
    private const array ACTIONS = ['inventory.attach_application', 'inventory.detach_application'];

    public function refreshesPage(AuditAction $action): bool
    {
        return \in_array($action->name, self::ACTIONS, true);
    }
}
