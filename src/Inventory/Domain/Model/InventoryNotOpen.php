<?php

declare(strict_types=1);

namespace Inventory\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * The inventory is completed, rejected or discarded: nothing more can be uploaded.
 */
final class InventoryNotOpen extends BusinessRuleViolated
{
    public function __construct(string $detail = '')
    {
        parent::__construct('inventory_not_open', $detail);
    }
}
