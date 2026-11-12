<?php

declare(strict_types=1);

namespace Exposure\UI\Controller\Team;

use Exposure\UI\Controller\RouteCollection;
use Exposure\UI\Frame\TurboFrame;
use Shared\UI\Frame\FrameContext;
use Shared\UI\Frame\FrameEndpoint;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(path: '/teams/{teamId}/hosts/{hostId}/frames/exposure', name: RouteCollection::HOST_SUMMARY_FRAME->value, requirements: ['teamId' => Requirement::UUID, 'hostId' => Requirement::UUID], methods: ['GET'])]
final readonly class HostSummaryFrameController
{
    public function __construct(private FrameEndpoint $endpoint)
    {
    }

    public function __invoke(Request $request, string $teamId, string $hostId): Response
    {
        return $this->endpoint->respond($request, TurboFrame::HostSummary, new FrameContext(['teamId' => $teamId, 'hostId' => $hostId]));
    }
}
