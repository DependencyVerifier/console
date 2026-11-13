<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Organization;

use Shared\Domain\Event\DomainEvent;

/**
 * Published language of Organization: an environment of a team changed what the other contexts copy of it (whether
 * it is a production one).
 */
final readonly class EnvironmentChanged implements DomainEvent
{
    public function __construct(public string $teamId, public string $environmentId)
    {
    }
}
