<?php

declare(strict_types=1);

namespace Inventory\UI\Controller\Team;

use Inventory\UI\Controller\RouteCollection;
use Inventory\UI\Frame\TurboFrame;
use Shared\UI\Frame\FrameContext;
use Shared\UI\Frame\FrameEndpoint;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(path: '/teams/{teamId}/frames/inventory', name: RouteCollection::TEAM_INVENTORY_FRAME->value, requirements: ['teamId' => Requirement::UUID], methods: ['GET'])]
final readonly class TeamInventoryFrameController
{
    public function __construct(private FrameEndpoint $endpoint)
    {
    }

    public function __invoke(Request $request, string $teamId): Response
    {
        return $this->endpoint->respond($request, TurboFrame::TeamInventory, new FrameContext(['teamId' => $teamId]));
    }
}
