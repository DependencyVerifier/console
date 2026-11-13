<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Fleet;

use Shared\Domain\Event\DomainEvent;

/**
 * Published language of Fleet: the display name (null: its hostname) and the IP addresses a human gave a host, for
 * the contexts that show hosts.
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
