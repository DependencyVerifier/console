<?php

declare(strict_types=1);

namespace Exposure\UI\Controller;

use Exposure\UI\Frame\TurboFrame;
use Shared\UI\Frame\FrameContext;
use Shared\UI\Frame\FrameEndpoint;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/frames/portfolio', name: RouteCollection::PORTFOLIO_FRAME->value, methods: ['GET'])]
final readonly class PortfolioFrameController
{
    public function __construct(private FrameEndpoint $endpoint)
    {
    }

    public function __invoke(Request $request): Response
    {
        return $this->endpoint->respond($request, TurboFrame::Portfolio, new FrameContext());
    }
}
