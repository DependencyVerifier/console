<?php

declare(strict_types=1);

namespace Organization\Domain\Event;

use Shared\Domain\Event\DomainEvent;

final readonly class TeamCreated implements DomainEvent
{
    public function __construct(public string $teamId)
    {
    }
}
