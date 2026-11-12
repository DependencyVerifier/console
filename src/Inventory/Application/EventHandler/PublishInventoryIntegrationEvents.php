<?php

declare(strict_types=1);

namespace Inventory\Application\EventHandler;

use Inventory\Domain\Event\InventoryProcessed;
use Shared\Application\Bus\EventBus;
use Shared\Application\Bus\EventHandler;
use Shared\Application\Contract\Inventory\InventoryChanged;

/**
 * Translates Inventory's internal facts into its published language: the other contexts only consume the contracts.
 */
final readonly class PublishInventoryIntegrationEvents implements EventHandler
{
    public function __construct(private EventBus $events)
    {
    }

    public function __invoke(InventoryProcessed $event): void
    {
        $this->events->publish(new InventoryChanged($event->teamId));
    }
}
