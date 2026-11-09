<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Security;

use Identity\UI\Controller\RouteCollection;
use Shared\UI\Security\CsrfRejection;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * form_login failure handler: an invalid CSRF token is a 403 with one `csrf.rejected` entry (never a login
 * failure); anything else goes back to the login form with a generic message (the real reason is only audited).
 */
final readonly class LoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    public const string CSRF_TOKEN_ID = 'authenticate';

    public function __construct(private CsrfRejection $csrfRejection, private UrlGeneratorInterface $urls)
    {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof InvalidCsrfTokenException) {
            return $this->csrfRejection->reject(self::CSRF_TOKEN_ID);
        }

        $request->getSession()->set(SessionKeys::AUTH_ERROR, $exception instanceof TooManyLoginAttemptsAuthenticationException
            ? 'identity.login.error.throttled'
            : 'identity.login.error.invalid_credentials');

        return new RedirectResponse($this->urls->generate(RouteCollection::LOGIN->prefixed()));
    }
}
