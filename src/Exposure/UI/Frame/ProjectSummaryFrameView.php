<?php

declare(strict_types=1);

namespace Exposure\UI\Frame;

use Exposure\Application\Query\ExposureScope;
use Exposure\UI\Controller\RouteCollection;
use Shared\UI\Frame\Frame;
use Shared\UI\Frame\FrameContext;
use Shared\UI\Frame\FrameView;

/**
 * On the project overview.
 */
final readonly class ProjectSummaryFrameView implements FrameView
{
    public function __construct(private SummaryFrameView $summary)
    {
    }

    public function frame(): Frame
    {
        return TurboFrame::ProjectSummary;
    }

    public function isVisible(FrameContext $context): bool
    {
        return true;
    }

    public function render(FrameContext $context): string
    {
        return $this->summary->render($context, ExposureScope::Project, $context->get('projectId'), RouteCollection::PROJECT_FINDINGS->prefixed(), ['teamId' => $context->get('teamId'), 'projectId' => $context->get('projectId')]);
    }
}
