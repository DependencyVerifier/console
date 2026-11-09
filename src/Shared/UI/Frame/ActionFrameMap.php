<?php

declare(strict_types=1);

namespace Shared\UI\Frame;

use Shared\Application\Audit\AuditAction;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * "Action → frames to refresh", one table per context, listing only its own frames (it may map the action of
 * another context to one of them). Aggregated by FrameRefresher.
 */
#[AutoconfigureTag]
interface ActionFrameMap
{
    /**
     * @return list<Frame>
     */
    public function framesFor(AuditAction $action): array;
}
