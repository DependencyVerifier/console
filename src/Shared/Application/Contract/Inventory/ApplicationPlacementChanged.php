<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Inventory;

use Shared\Domain\Event\DomainEvent;

/**
 * Published language of Inventory: an application of the team was attached, moved or detached by hand (Exposure
 * places its findings again).
 */
final readonly class ApplicationPlacementChanged implements DomainEvent
{
    public function __construct(public string $teamId)
    {
    }
}
