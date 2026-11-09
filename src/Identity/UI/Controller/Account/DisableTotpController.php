<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Account;

use Identity\Application\Command\SecondFactor\DisableTotp;
use Identity\Infrastructure\Security\SecurityUser;
use Identity\UI\Controller\RouteCollection;
use Identity\UI\Security\RequiresRecentAuthentication;
use Shared\UI\Action\ActionResponder;
use Shared\UI\Action\StreamOrRedirect;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route(path: '/account/security/totp/disable', name: RouteCollection::ACCOUNT_TOTP_DISABLE->value, methods: ['POST'])]
#[RequiresRecentAuthentication(returnRoute: 'identity_account_security')]
final readonly class DisableTotpController
{
    public function __construct(private ActionResponder $responder, private AccountForms $forms, private StreamOrRedirect $redirect)
    {
    }

    public function __invoke(Request $request, #[CurrentUser] SecurityUser $user): Response
    {
        return $this->responder->handle(
            $request,
            $this->forms->action($this->forms->button(AccountForms::TOTP_DISABLE), 'identity.account.totp.title', RouteCollection::ACCOUNT_TOTP_DISABLE->prefixed()),
            static fn (): DisableTotp => new DisableTotp($user->id),
            $this->redirect->withSuccessKey('identity.account.totp.disabled'),
            new RouteReference(RouteCollection::LOGIN->prefixed()),
        );
    }
}
