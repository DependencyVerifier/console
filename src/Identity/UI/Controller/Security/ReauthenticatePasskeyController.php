<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Security;

use Identity\Application\Command\Passkey\VerifyPasskey;
use Identity\Infrastructure\Security\SecondFactorThrottledException;
use Identity\Infrastructure\Security\SecurityUser;
use Identity\Infrastructure\Security\SessionKeys;
use Identity\Infrastructure\Security\TwoFactor\CodeVerifyingProvider;
use Identity\UI\Controller\RouteCollection;
use Identity\UI\Passkey\PasskeyOptionsResponder;
use Identity\UI\Security\ReauthenticationSuccess;
use Shared\UI\Security\CsrfRejection;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Decision 8 with a passkey: it proves the account holder on its own (user verification), checked by VerifyPasskey
 * like a second factor (same windows, one audit entry). A refusal goes back to the page with its message.
 */
#[Route(path: '/reauthenticate/passkey', name: RouteCollection::REAUTHENTICATE_PASSKEY->value, methods: ['POST'])]
final readonly class ReauthenticatePasskeyController
{
    public function __construct(
        private CodeVerifyingProvider $codes,
        private CsrfTokenManagerInterface $csrf,
        private CsrfRejection $csrfRejection,
        private ReauthenticationSuccess $success,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function __invoke(Request $request, #[CurrentUser] SecurityUser $user): Response
    {
        $tokenId = PasskeyAssertionOptionsController::REAUTHENTICATE_TOKEN_ID;
        if (!$this->csrf->isTokenValid(new CsrfToken($tokenId, (string) $request->request->get('_csrf_token')))) {
            return $this->csrfRejection->reject($tokenId);
        }

        $options = PasskeyOptionsResponder::take($request);
        try {
            $accepted = '' !== $options && $this->codes->verify(new VerifyPasskey($user->id, (string) $request->request->get('_passkey'), Uuid::v7()->toRfc4122(), $options));
        } catch (SecondFactorThrottledException) {
            $accepted = false;
        }

        if ($accepted) {
            return $this->success->respond($request);
        }

        $request->getSession()->set(SessionKeys::AUTH_ERROR, 'identity.login.error.passkey');

        return new RedirectResponse($this->urls->generate(RouteCollection::REAUTHENTICATE->prefixed()), Response::HTTP_SEE_OTHER);
    }
}
