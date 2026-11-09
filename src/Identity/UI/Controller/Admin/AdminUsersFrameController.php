<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Admin;

use Identity\UI\Controller\RouteCollection;
use Identity\UI\Frame\TurboFrame;
use Shared\UI\Frame\FrameContext;
use Shared\UI\Frame\FrameEndpoint;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/admin/users/frames/list', name: RouteCollection::ADMIN_USERS_FRAME->value, methods: ['GET'])]
final readonly class AdminUsersFrameController
{
    public function __construct(private FrameEndpoint $endpoint)
    {
    }

    public function __invoke(Request $request): Response
    {
        return $this->endpoint->respond($request, TurboFrame::AdminUsers, new FrameContext());
    }
}
