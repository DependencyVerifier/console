<?php

declare(strict_types=1);

namespace Inventory\UI\Frame;

use Inventory\UI\Controller\RouteCollection;
use Shared\UI\Frame\Frame;
use Shared\UI\Frame\FrameContext;
use Shared\UI\Routing\RouteReference;

/**
 * Lazy zones of Inventory that are not lists (lists are DataTables).
 */
enum TurboFrame: string implements Frame
{
    case TeamInventory = 'team-inventory';

    public function id(): string
    {
        return $this->value;
    }

    public function route(): string
    {
        return match ($this) {
            self::TeamInventory => RouteCollection::TEAM_INVENTORY_FRAME->prefixed(),
        };
    }

    public function fallbackRoute(FrameContext $context): RouteReference
    {
        return match ($this) {
            self::TeamInventory => new RouteReference(RouteCollection::TEAM_APPLICATIONS->prefixed(), ['teamId' => $context->get('teamId')]),
        };
    }
}
