<?php

declare(strict_types=1);

namespace Exposure\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * A finding was detected for the first time, or again after it was fixed (`reopened`).
 */
final readonly class FindingOpened implements DomainEvent
{
    public function __construct(public string $findingId, public string $teamId, public bool $reopened)
    {
    }
}
