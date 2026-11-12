<?php

declare(strict_types=1);

namespace Inventory\UI\Frame;

use Inventory\Application\Query\Team\CountInventory;
use Shared\Application\Bus\QueryBus;
use Shared\UI\Frame\Frame;
use Shared\UI\Frame\FrameContext;
use Shared\UI\Frame\FrameView;
use Twig\Environment;

/**
 * The inventory figures of a team on its overview (Organization's page): every member of the team sees them, a
 * TEAM_GUEST its applications only (CountInventory judges).
 */
final readonly class TeamInventoryFrameView implements FrameView
{
    public function __construct(private QueryBus $queries, private Environment $twig)
    {
    }

    public function frame(): Frame
    {
        return TurboFrame::TeamInventory;
    }

    public function isVisible(FrameContext $context): bool
    {
        return true;
    }

    public function render(FrameContext $context): string
    {
        return $this->twig->render('inventory/team/counts_frame.html.twig', [
            'teamId' => $context->get('teamId'),
            'counts' => $this->queries->ask(new CountInventory($context->get('teamId'))),
        ]);
    }
}
