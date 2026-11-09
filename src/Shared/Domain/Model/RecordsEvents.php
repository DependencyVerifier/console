<?php

declare(strict_types=1);

namespace Shared\Domain\Model;

use Shared\Domain\Event\DomainEvent;

/**
 * An aggregate root that records domain events. They are collected after flush and published once the command
 * transaction is committed (Shared\Infrastructure\Messenger\PublishDomainEventsMiddleware).
 */
interface RecordsEvents
{
    /**
     * Returns the recorded events and forgets them, so an event is never published twice.
     *
     * @return list<DomainEvent>
     */
    public function releaseEvents(): array;
}
