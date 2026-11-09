<?php

declare(strict_types=1);

namespace Organization\UI\Frame;

use Organization\Application\Query\Team\GetTeam;
use Shared\Application\Authorization\PermissionChecker;
use Shared\Application\Bus\QueryBus;
use Shared\Domain\Authorization\Permission;
use Shared\UI\Frame\Frame;
use Shared\UI\Frame\FrameContext;
use Shared\UI\Frame\FrameView;
use Twig\Environment;

/**
 * Quota gauges of a team (`<meter>`, no inline style), with the 80 % banner.
 */
final readonly class TeamQuotasFrameView implements FrameView
{
    public function __construct(private QueryBus $queries, private PermissionChecker $permissions, private Environment $twig)
    {
    }

    public function frame(): Frame
    {
        return TurboFrame::TeamQuotas;
    }

    public function isVisible(FrameContext $context): bool
    {
        return $this->permissions->isGranted(Permission::TeamEdit, $context->get('teamId'))
            || $this->permissions->isGranted(Permission::TeamMembersManage, $context->get('teamId'));
    }

    public function render(FrameContext $context): string
    {
        return $this->twig->render('organization/team/quotas_frame.html.twig', ['team' => $this->queries->ask(new GetTeam($context->get('teamId')))]);
    }
}
