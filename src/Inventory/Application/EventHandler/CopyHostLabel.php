<?php

declare(strict_types=1);

namespace Inventory\Application\EventHandler;

use Inventory\Application\Command\Host\RecordHostLabel;
use Shared\Application\Bus\CommandBus;
use Shared\Application\Bus\EventHandler;
use Shared\Application\Contract\Fleet\HostLabelled;

/**
 * Fleet labelled a host: Inventory keeps a copy to show it under its display name, without reading Fleet's tables.
 */
final readonly class CopyHostLabel implements EventHandler
{
    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(HostLabelled $event): void
    {
        $this->commands->dispatch(new RecordHostLabel($event->hostId, $event->teamId, $event->displayName, $event->addresses));
    }
}
