<?php

declare(strict_types=1);

namespace Exposure\UI\Frame;

use Exposure\UI\Controller\RouteCollection;
use Shared\UI\Frame\Frame;
use Shared\UI\Frame\FrameContext;
use Shared\UI\Routing\RouteReference;

/**
 * Lazy zones of Exposure: the figures of a team, a project or a host on their pages.
 */
enum TurboFrame: string implements Frame
{
    case TeamSummary = 'team-exposure';
    case ProjectSummary = 'project-exposure';
    case HostSummary = 'host-exposure';

    public function id(): string
    {
        return $this->value;
    }

    public function route(): string
    {
        return match ($this) {
            self::TeamSummary => RouteCollection::TEAM_SUMMARY_FRAME->prefixed(),
            self::ProjectSummary => RouteCollection::PROJECT_SUMMARY_FRAME->prefixed(),
            self::HostSummary => RouteCollection::HOST_SUMMARY_FRAME->prefixed(),
        };
    }

    public function fallbackRoute(FrameContext $context): RouteReference
    {
        return match ($this) {
            self::ProjectSummary => new RouteReference(RouteCollection::PROJECT_FINDINGS->prefixed(), ['teamId' => $context->get('teamId'), 'projectId' => $context->get('projectId')]),
            default => new RouteReference(RouteCollection::TEAM_FINDINGS->prefixed(), ['teamId' => $context->get('teamId')]),
        };
    }
}
