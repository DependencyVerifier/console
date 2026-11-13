<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Security;

use Identity\Application\Port\PasskeyOptions;
use Identity\Application\Query\Passkey\GetPasskeyRequestOptions;
use Identity\Infrastructure\Security\PasskeyAuthenticator;
use Identity\UI\Controller\RouteCollection;
use Identity\UI\Passkey\PasskeyOptionsResponder;
use Shared\Application\Bus\QueryBus;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Signing in with a passkey: the options (any discoverable passkey of this instance), then the answer, which
 * PasskeyAuthenticator handles before this controller (reached only when it does not, back to the login page).
 */
final readonly class PasskeyLoginController
{
    public function __construct(private PasskeyOptionsResponder $responder, private QueryBus $queries, private UrlGeneratorInterface $urls)
    {
    }

    #[Route(path: '/login/passkey/options', name: RouteCollection::LOGIN_PASSKEY_OPTIONS->value, methods: ['POST'])]
    public function options(Request $request): Response
    {
        return $this->responder->respond($request, PasskeyAuthenticator::CSRF_TOKEN_ID, fn (): PasskeyOptions => $this->queries->ask(new GetPasskeyRequestOptions()));
    }

    #[Route(path: '/login/passkey', name: RouteCollection::LOGIN_PASSKEY->value, methods: ['POST'])]
    public function signIn(): Response
    {
        return new RedirectResponse($this->urls->generate(RouteCollection::LOGIN->prefixed()), Response::HTTP_SEE_OTHER);
    }
}
