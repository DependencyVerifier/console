<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Account;

use Identity\Application\Command\Passkey\RevokePasskey;
use Identity\Infrastructure\Security\SecurityUser;
use Identity\UI\Controller\RouteCollection;
use Identity\UI\Security\RequiresRecentAuthentication;
use Shared\UI\Action\ActionResponder;
use Shared\UI\Action\StreamOrRedirect;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Every session is signed out (this one too): back to the login page.
 */
#[Route(path: '/account/security/passkeys/{passkeyId}/revoke', name: RouteCollection::ACCOUNT_PASSKEY_REVOKE->value, requirements: ['passkeyId' => Requirement::UUID], methods: ['POST'])]
#[RequiresRecentAuthentication(returnRoute: 'identity_account_security')]
final readonly class RevokePasskeyController
{
    public function __construct(private ActionResponder $responder, private AccountForms $forms, private StreamOrRedirect $redirect)
    {
    }

    public function __invoke(Request $request, #[CurrentUser] SecurityUser $user, string $passkeyId): Response
    {
        return $this->responder->handle(
            $request,
            $this->forms->action($this->forms->button(AccountForms::passkeyRevoke($passkeyId)), 'identity.account.passkeys.title', RouteCollection::ACCOUNT_PASSKEY_REVOKE->prefixed()),
            static fn (): RevokePasskey => new RevokePasskey($user->id, $passkeyId),
            $this->redirect->withSuccessKey('identity.account.passkeys.revoked'),
            new RouteReference(RouteCollection::LOGIN->prefixed()),
        );
    }
}
