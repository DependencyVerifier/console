<?php

declare(strict_types=1);

namespace Inventory\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * An inventory is turned into applications, images and packages: what the matching (step 4) will react to.
 */
final readonly class InventoryProcessed implements DomainEvent
{
    public function __construct(public string $inventoryId, public string $teamId)
    {
    }
}
