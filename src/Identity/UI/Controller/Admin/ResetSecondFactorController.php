<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Admin;

use Identity\Application\Command\Administration\ResetSecondFactor;
use Identity\UI\Controller\RouteCollection;
use Identity\UI\Security\RequiresRecentAuthentication;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(path: '/admin/users/{userId}/reset-second-factor', name: RouteCollection::ADMIN_USER_RESET_SECOND_FACTOR->value, requirements: ['userId' => Requirement::UUID], methods: ['POST'])]
#[RequiresRecentAuthentication(returnRoute: 'identity_admin_users')]
final readonly class ResetSecondFactorController
{
    public const string ACTION = 'reset_second_factor';

    public function __construct(private AdminUserAction $action)
    {
    }

    public function __invoke(Request $request, string $userId): Response
    {
        return $this->action->handle($request, self::ACTION, $userId, static fn (): ResetSecondFactor => new ResetSecondFactor($userId));
    }
}
