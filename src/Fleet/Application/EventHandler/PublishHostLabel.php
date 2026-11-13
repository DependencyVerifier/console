<?php

declare(strict_types=1);

namespace Fleet\Application\EventHandler;

use Fleet\Domain\Event\HostLabelled;
use Shared\Application\Bus\EventBus;
use Shared\Application\Bus\EventHandler;
use Shared\Application\Contract\Fleet\HostLabelled as PublishedHostLabelled;

/**
 * Publishes the label of a host (Inventory shows hosts under their display name).
 */
final readonly class PublishHostLabel implements EventHandler
{
    public function __construct(private EventBus $events)
    {
    }

    public function __invoke(HostLabelled $event): void
    {
        $this->events->publish(new PublishedHostLabelled($event->hostId, $event->teamId, $event->displayName, $event->addresses));
    }
}
