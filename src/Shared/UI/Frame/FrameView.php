<?php

declare(strict_types=1);

namespace Shared\UI\Frame;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The single recipe of a frame's content, composed by the lazy endpoint and by the Turbo Stream after an action.
 * It asks the query bus: the query handler is the only judge of scope and permission (its refusal is audited once
 * by AuditDeniedQueryMiddleware). `isVisible` is the same non-audited check as the page's is_granted, used before
 * refreshing a frame after an action.
 */
#[AutoconfigureTag]
interface FrameView
{
    public function frame(): Frame;

    public function isVisible(FrameContext $context): bool;

    /**
     * Inner HTML of the frame (the <turbo-frame> wrapper is added by FrameRenderer).
     */
    public function render(FrameContext $context): string;
}
