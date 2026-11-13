<?php

declare(strict_types=1);

namespace Fleet\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * A human changed the display name or the addresses of a host.
 */
final readonly class HostLabelled implements DomainEvent
{
    /**
     * @param list<string> $addresses
     */
    public function __construct(public string $hostId, public string $teamId, public ?string $displayName, public array $addresses)
    {
    }
}
