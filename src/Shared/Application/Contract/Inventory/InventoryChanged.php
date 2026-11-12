<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Inventory;

use Shared\Domain\Event\DomainEvent;

/**
 * Published language of Inventory: an inventory of the team was processed, its packages may have changed
 * (Vulnerability watches the new ones, Exposure computes the findings of the team again).
 */
final readonly class InventoryChanged implements DomainEvent
{
    public function __construct(public string $teamId)
    {
    }
}
