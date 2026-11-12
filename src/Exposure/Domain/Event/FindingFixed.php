<?php

declare(strict_types=1);

namespace Exposure\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * A finding is no longer produced: the version was upgraded, the application removed, the advisory withdrawn.
 */
final readonly class FindingFixed implements DomainEvent
{
    public function __construct(public string $findingId, public string $teamId)
    {
    }
}
