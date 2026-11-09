<?php

declare(strict_types=1);

namespace Organization\UI\Controller\Team;

use Organization\UI\Controller\RouteCollection;
use Organization\UI\Frame\TurboFrame;
use Shared\UI\Frame\FrameContext;
use Shared\UI\Frame\FrameEndpoint;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(path: '/teams/{teamId}/frames/quotas', name: RouteCollection::TEAM_QUOTAS_FRAME->value, requirements: ['teamId' => Requirement::UUID], methods: ['GET'])]
final readonly class TeamQuotasFrameController
{
    public function __construct(private FrameEndpoint $endpoint)
    {
    }

    public function __invoke(Request $request, string $teamId): Response
    {
        return $this->endpoint->respond($request, TurboFrame::TeamQuotas, new FrameContext(['teamId' => $teamId]));
    }
}
