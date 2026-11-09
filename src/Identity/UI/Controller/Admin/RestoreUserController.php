<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Admin;

use Identity\Application\Command\Administration\RestoreUser;
use Identity\UI\Controller\RouteCollection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(path: '/admin/users/{userId}/restore', name: RouteCollection::ADMIN_USER_RESTORE->value, requirements: ['userId' => Requirement::UUID], methods: ['POST'])]
final readonly class RestoreUserController
{
    public const string ACTION = 'restore';

    public function __construct(private AdminUserAction $action)
    {
    }

    public function __invoke(Request $request, string $userId): Response
    {
        return $this->action->handle($request, self::ACTION, $userId, static fn (): RestoreUser => new RestoreUser($userId));
    }
}
