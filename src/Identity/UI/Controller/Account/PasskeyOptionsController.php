<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Account;

use Identity\Application\Port\PasskeyOptions;
use Identity\Application\Query\Passkey\GetPasskeyCreationOptions;
use Identity\Infrastructure\Security\SecurityUser;
use Identity\UI\Controller\RouteCollection;
use Identity\UI\Passkey\PasskeyOptionsResponder;
use Shared\Application\Bus\QueryBus;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route(path: '/account/security/passkeys/options', name: RouteCollection::ACCOUNT_PASSKEY_OPTIONS->value, methods: ['POST'])]
final readonly class PasskeyOptionsController
{
    public function __construct(private PasskeyOptionsResponder $responder, private QueryBus $queries)
    {
    }

    public function __invoke(Request $request, #[CurrentUser] SecurityUser $user): Response
    {
        return $this->responder->respond($request, AccountForms::PASSKEY_REGISTER, fn (): PasskeyOptions => $this->queries->ask(new GetPasskeyCreationOptions($user->id)));
    }
}
