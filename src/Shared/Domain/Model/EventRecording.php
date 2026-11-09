<?php

declare(strict_types=1);

namespace Shared\Domain\Model;

use Shared\Domain\Event\DomainEvent;

/**
 * Default implementation of RecordsEvents (composition over an abstract base class).
 */
trait EventRecording
{
    /** @var list<DomainEvent> */
    private array $recordedEvents = [];

    /**
     * @return list<DomainEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }

    private function recordThat(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }
}
