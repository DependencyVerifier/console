<?php

declare(strict_types=1);

namespace Inventory\Application\EventHandler;

use Inventory\Application\Command\Inventory\ProcessInventory;
use Inventory\Domain\Event\InventoryCompleted;
use Shared\Application\Bus\CommandBus;
use Shared\Application\Bus\EventHandler;

/**
 * A completed inventory is processed by the worker: ProcessInventory is routed to the `ingest` queue.
 */
final readonly class QueueInventoryProcessing implements EventHandler
{
    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(InventoryCompleted $event): void
    {
        $this->commands->dispatch(new ProcessInventory($event->inventoryId));
    }
}
