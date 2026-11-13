<?php

declare(strict_types=1);

namespace Notification\Application\EventHandler;

use Notification\Application\Command\Send\SendDelivery;
use Notification\Domain\Event\DeliveryScheduled;
use Shared\Application\Bus\CommandBus;
use Shared\Application\Bus\EventHandler;

/**
 * An immediate delivery is attempted at once, once its notice is committed.
 */
final readonly class QueueDelivery implements EventHandler
{
    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(DeliveryScheduled $event): void
    {
        $this->commands->dispatch(new SendDelivery($event->deliveryId));
    }
}
