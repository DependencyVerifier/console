<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Security;

use Identity\UI\Controller\RouteCollection;
use Shared\UI\Security\CsrfRejection;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * Second factor failure handler (scheb `two_factor.failure_handler`), composing what scheb's default does:
 * - invalid CSRF token: 403, one `csrf.rejected` entry, the code is never evaluated (CSRF badge checked first);
 * - limiter refused: the 2FA token is cancelled and the session invalidated, back to the login form;
 * - wrong code: back to the second factor form with a generic message (the code verification audited itself).
 */
final readonly class TwoFactorFailureHandler implements AuthenticationFailureHandlerInterface
{
    public const string CSRF_TOKEN_ID = 'two_factor';

    public function __construct(
        private CsrfRejection $csrfRejection,
        private UrlGeneratorInterface $urls,
        private TokenStorageInterface $tokens,
    ) {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof InvalidCsrfTokenException) {
            return $this->csrfRejection->reject(self::CSRF_TOKEN_ID);
        }

        if ($exception instanceof SecondFactorThrottledException) {
            $this->tokens->setToken(null);
            $request->getSession()->invalidate();
            $request->getSession()->set(SessionKeys::AUTH_ERROR, $exception->getMessageKey());

            return new RedirectResponse($this->urls->generate(RouteCollection::LOGIN->prefixed()));
        }

        $request->getSession()->set(SessionKeys::AUTH_ERROR, 'identity.two_factor.error.invalid_code');

        return new RedirectResponse($this->urls->generate('2fa_login'));
    }
}
