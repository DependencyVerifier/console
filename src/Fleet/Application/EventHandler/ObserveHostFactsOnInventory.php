<?php

declare(strict_types=1);

namespace Fleet\Application\EventHandler;

use Fleet\Application\Command\Host\ObserveHostFacts;
use Shared\Application\Bus\CommandBus;
use Shared\Application\Bus\EventHandler;
use Shared\Application\Contract\Inventory\HostFactsObserved;

/**
 * Inventory observed the facts of a host: Fleet keeps its own copy (the Hosts list reads it) without reading
 * Inventory's tables, and may promote the identity of the host.
 */
final readonly class ObserveHostFactsOnInventory implements EventHandler
{
    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(HostFactsObserved $event): void
    {
        $this->commands->dispatch(new ObserveHostFacts(
            $event->hostId,
            $event->teamId,
            $event->agentId,
            $event->hostname,
            $event->osId,
            $event->osVersionId,
            $event->osPrettyName,
            $event->kernel,
            $event->arch,
            $event->dockerEngineId,
        ));
    }
}
