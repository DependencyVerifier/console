<?php

declare(strict_types=1);

namespace Inventory\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * Every snapshot of an inventory is stored: its processing is queued (ProcessInventory, queue `ingest`).
 */
final readonly class InventoryCompleted implements DomainEvent
{
    public function __construct(public string $inventoryId, public string $teamId)
    {
    }
}
