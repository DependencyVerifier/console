<?php

declare(strict_types=1);

namespace Exposure\UI\Frame;

use Exposure\Application\Query\ExposureScope;
use Shared\UI\Frame\Frame;
use Shared\UI\Frame\FrameContext;
use Shared\UI\Frame\FrameView;

/**
 * On the host page (its OS, runtimes and OS packages).
 */
final readonly class HostSummaryFrameView implements FrameView
{
    public function __construct(private SummaryFrameView $summary)
    {
    }

    public function frame(): Frame
    {
        return TurboFrame::HostSummary;
    }

    public function isVisible(FrameContext $context): bool
    {
        return true;
    }

    public function render(FrameContext $context): string
    {
        return $this->summary->render($context, ExposureScope::Host, $context->get('hostId'), null);
    }
}
