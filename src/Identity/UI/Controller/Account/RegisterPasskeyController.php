<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Account;

use Identity\Application\Command\Passkey\RegisterPasskey;
use Identity\Infrastructure\Security\SecurityUser;
use Identity\UI\Controller\RouteCollection;
use Identity\UI\Passkey\PasskeyOptionsResponder;
use Identity\UI\Security\RequiresRecentAuthentication;
use Shared\UI\Action\ActionResponder;
use Shared\UI\Action\FormData;
use Shared\UI\Action\StreamOrRedirect;
use Shared\UI\Routing\RouteReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

#[Route(path: '/account/security/passkeys', name: RouteCollection::ACCOUNT_PASSKEY_REGISTER->value, methods: ['POST'])]
#[RequiresRecentAuthentication(returnRoute: 'identity_account_security')]
final readonly class RegisterPasskeyController
{
    public function __construct(private ActionResponder $responder, private AccountForms $forms, private StreamOrRedirect $redirect)
    {
    }

    public function __invoke(Request $request, #[CurrentUser] SecurityUser $user): Response
    {
        $form = $this->forms->passkey();
        $options = PasskeyOptionsResponder::take($request);

        return $this->responder->handle(
            $request,
            $this->forms->action($form, 'identity.account.passkeys.title', RouteCollection::ACCOUNT_PASSKEY_REGISTER->prefixed()),
            static fn (): RegisterPasskey => new RegisterPasskey($user->id, Uuid::v7()->toRfc4122(), FormData::string($form, 'name'), FormData::string($form, 'response'), $options),
            $this->redirect->withSuccessKey('identity.account.passkeys.registered'),
            new RouteReference(RouteCollection::ACCOUNT_SECURITY->prefixed()),
        );
    }
}
