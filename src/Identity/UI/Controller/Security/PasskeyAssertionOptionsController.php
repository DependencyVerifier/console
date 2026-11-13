<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Security;

use Identity\Application\Port\PasskeyOptions;
use Identity\Application\Query\Passkey\GetPasskeyRequestOptions;
use Identity\Infrastructure\Security\SecurityUser;
use Identity\UI\Controller\RouteCollection;
use Identity\UI\Passkey\PasskeyOptionsResponder;
use Shared\Application\Bus\QueryBus;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The options of the account holder's passkeys: as the second factor (`/2fa…`, a second factor in progress) and to
 * authenticate again (`/reauthenticate…`, a full session).
 */
final readonly class PasskeyAssertionOptionsController
{
    public const string REAUTHENTICATE_TOKEN_ID = 'reauthenticate_passkey';

    public function __construct(private PasskeyOptionsResponder $responder, private QueryBus $queries)
    {
    }

    #[Route(path: '/2fa/passkey/options', name: RouteCollection::TWO_FACTOR_PASSKEY_OPTIONS->value, methods: ['POST'])]
    public function secondFactor(Request $request, #[CurrentUser] SecurityUser $user): Response
    {
        return $this->respond($request, 'two_factor', $user);
    }

    #[Route(path: '/reauthenticate/passkey/options', name: RouteCollection::REAUTHENTICATE_PASSKEY_OPTIONS->value, methods: ['POST'])]
    public function reauthenticate(Request $request, #[CurrentUser] SecurityUser $user): Response
    {
        return $this->respond($request, self::REAUTHENTICATE_TOKEN_ID, $user);
    }

    private function respond(Request $request, string $csrfTokenId, SecurityUser $user): Response
    {
        return $this->responder->respond($request, $csrfTokenId, fn (): PasskeyOptions => $this->queries->ask(new GetPasskeyRequestOptions($user->id)));
    }
}
