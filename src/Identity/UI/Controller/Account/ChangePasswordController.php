<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Account;

use Identity\Application\Command\Password\ChangePassword;
use Identity\Infrastructure\Security\SecurityUser;
use Identity\UI\Controller\RouteCollection;
use Identity\UI\Security\RequiresRecentAuthentication;
use Shared\UI\Action\ActionResponder;
use Shared\UI\Action\FormData;
use Shared\UI\Action\StreamOrRedirect;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Every session is revoked, this one included: the user signs in again with the new password.
 */
#[Route(path: '/account/security/password', name: RouteCollection::ACCOUNT_PASSWORD->value, methods: ['POST'])]
#[RequiresRecentAuthentication(returnRoute: 'identity_account_security')]
final readonly class ChangePasswordController
{
    public function __construct(private ActionResponder $responder, private AccountForms $forms, private StreamOrRedirect $redirect)
    {
    }

    public function __invoke(Request $request, #[CurrentUser] SecurityUser $user): Response
    {
        $form = $this->forms->password();

        return $this->responder->handle(
            $request,
            $this->forms->action($form, 'identity.account.password.title', RouteCollection::ACCOUNT_PASSWORD->prefixed()),
            static fn (): ChangePassword => new ChangePassword($user->id, FormData::string($form, 'password')),
            $this->redirect->withSuccessKey('identity.account.password.done'),
            new RouteReference(RouteCollection::LOGIN->prefixed()),
        );
    }
}
