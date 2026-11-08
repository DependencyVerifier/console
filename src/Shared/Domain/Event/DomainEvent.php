<?php

declare(strict_types=1);

namespace Shared\Domain\Event;

/**
 * Marker for facts raised by an aggregate. Published on the event bus once the command succeeds.
 */
interface DomainEvent
{
}
