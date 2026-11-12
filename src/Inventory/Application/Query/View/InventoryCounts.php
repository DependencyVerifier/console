<?php

declare(strict_types=1);

namespace Inventory\Application\Query\View;

/**
 * Applications still found by their agents; `unattached` and `images` are null for a TEAM_GUEST (it sees neither).
 */
final readonly class InventoryCounts
{
    public function __construct(public int $applications, public ?int $unattached, public ?int $images)
    {
    }
}
