<?php

declare(strict_types=1);

namespace Notification\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * An immediate delivery waits in the outbox: it is attempted at once, in the `notifications` queue.
 */
final readonly class DeliveryScheduled implements DomainEvent
{
    public function __construct(public string $deliveryId)
    {
    }
}
