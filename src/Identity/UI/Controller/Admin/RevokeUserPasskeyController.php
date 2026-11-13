<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Admin;

use Identity\Application\Command\Passkey\RevokePasskey;
use Identity\UI\Controller\RouteCollection;
use Identity\UI\Security\RequiresRecentAuthentication;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route(path: '/admin/users/{userId}/passkeys/{passkeyId}/revoke', name: RouteCollection::ADMIN_USER_REVOKE_PASSKEY->value, requirements: ['userId' => Requirement::UUID, 'passkeyId' => Requirement::UUID], methods: ['POST'])]
#[RequiresRecentAuthentication(returnRoute: 'identity_admin_users')]
final readonly class RevokeUserPasskeyController
{
    public const string ACTION = 'revoke_passkey';

    public function __construct(private AdminUserAction $action)
    {
    }

    public function __invoke(Request $request, string $userId, string $passkeyId): Response
    {
        return $this->action->handle($request, self::ACTION, $passkeyId, static fn (): RevokePasskey => new RevokePasskey($userId, $passkeyId));
    }
}
