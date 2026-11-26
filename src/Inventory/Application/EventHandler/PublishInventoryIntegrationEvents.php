<?php

declare(strict_types=1);

namespace Inventory\Application\EventHandler;

use Inventory\Domain\Event\ApplicationPlaced;
use Inventory\Domain\Event\HostFactsRecorded;
use Inventory\Domain\Event\InventoryProcessed;
use Shared\Application\Bus\EventBus;
use Shared\Application\Bus\EventHandler;
use Shared\Application\Contract\Inventory\ApplicationPlacementChanged;
use Shared\Application\Contract\Inventory\HostFactsObserved;
use Shared\Application\Contract\Inventory\InventoryChanged;

/**
 * Translates Inventory's internal facts into its published language: the other contexts only consume the contracts.
 */
final readonly class PublishInventoryIntegrationEvents implements EventHandler
{
    public function __construct(private EventBus $events)
    {
    }

    public function __invoke(InventoryProcessed|ApplicationPlaced|HostFactsRecorded $event): void
    {
        $this->events->publish(match (true) {
            $event instanceof InventoryProcessed => new InventoryChanged($event->teamId),
            $event instanceof ApplicationPlaced => new ApplicationPlacementChanged($event->teamId),
            $event instanceof HostFactsRecorded => new HostFactsObserved(
                $event->hostId,
                $event->teamId,
                $event->agentId,
                $event->facts->hostname,
                $event->facts->osId,
                $event->facts->osVersionId,
                $event->facts->osPrettyName,
                $event->facts->kernel,
                $event->facts->arch,
                $event->facts->dockerEngineId,
            ),
        });
    }
}
