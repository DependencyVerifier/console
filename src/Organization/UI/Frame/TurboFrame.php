<?php

declare(strict_types=1);

namespace Organization\UI\Frame;

use Organization\UI\Controller\RouteCollection;
use Shared\UI\Frame\Frame;
use Shared\UI\Frame\FrameContext;
use Shared\UI\Routing\RouteReference;

/**
 * Lazy zones of Organization that are not lists (lists are DataTables).
 */
enum TurboFrame: string implements Frame
{
    case TeamQuotas = 'team-quotas';

    public function id(): string
    {
        return $this->value;
    }

    public function route(): string
    {
        return match ($this) {
            self::TeamQuotas => RouteCollection::TEAM_QUOTAS_FRAME->prefixed(),
        };
    }

    public function fallbackRoute(FrameContext $context): RouteReference
    {
        return match ($this) {
            self::TeamQuotas => new RouteReference(RouteCollection::TEAM->prefixed(), ['teamId' => $context->get('teamId')]),
        };
    }
}
