<?php

declare(strict_types=1);

namespace Tests\Identity\Support;

use Shared\Application\Bus\EventBus;
use Shared\Domain\Event\DomainEvent;

final class RecordingEventBus implements EventBus
{
    /** @var list<DomainEvent> */
    public array $published = [];

    public function publish(DomainEvent ...$events): void
    {
        array_push($this->published, ...$events);
    }
}
