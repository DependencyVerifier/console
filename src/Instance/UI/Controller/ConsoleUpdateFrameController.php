<?php

declare(strict_types=1);

namespace Instance\UI\Controller;

use Instance\UI\Frame\TurboFrame;
use Shared\UI\Frame\FrameContext;
use Shared\UI\Frame\FrameEndpoint;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The "new version available" banner, loaded lazily at the top of every page of the super admin.
 */
#[Route(path: '/admin/frames/console-update', name: RouteCollection::CONSOLE_UPDATE_FRAME->value, methods: ['GET'])]
final readonly class ConsoleUpdateFrameController
{
    public function __construct(private FrameEndpoint $frameEndpoint)
    {
    }

    public function __invoke(Request $request): Response
    {
        return $this->frameEndpoint->respond($request, TurboFrame::ConsoleUpdate, new FrameContext([]));
    }
}
