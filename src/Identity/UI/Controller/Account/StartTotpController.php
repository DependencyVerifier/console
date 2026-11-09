<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Account;

use Identity\Application\Command\SecondFactor\StartTotpEnrollment;
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

#[Route(path: '/account/security/totp/start', name: RouteCollection::ACCOUNT_TOTP_START->value, methods: ['POST'])]
#[RequiresRecentAuthentication(returnRoute: 'identity_account_security')]
final readonly class StartTotpController
{
    public function __construct(private ActionResponder $responder, private AccountForms $forms, private StreamOrRedirect $redirect)
    {
    }

    public function __invoke(Request $request, #[CurrentUser] SecurityUser $user): Response
    {
        return $this->responder->handle(
            $request,
            $this->forms->action($this->forms->button(AccountForms::TOTP_START), 'identity.account.totp.title', RouteCollection::ACCOUNT_TOTP_START->prefixed()),
            static fn (): StartTotpEnrollment => new StartTotpEnrollment($user->id),
            $this->redirect->withSuccessKey('identity.account.totp.started'),
            new RouteReference(RouteCollection::ACCOUNT_SECURITY->prefixed()),
        );
    }
}
