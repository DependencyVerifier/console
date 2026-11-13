<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Security;

use Identity\Application\Command\Passkey\AuthenticateWithPasskey;
use Identity\Application\Port\PasskeyCeremony;
use Identity\Application\Query\Passkey\GetPasskeySignIn;
use Identity\Domain\Model\UserId;
use Identity\Domain\Repository\UserRepository;
use Identity\UI\Controller\RouteCollection;
use Shared\Application\Bus\CommandBus;
use Shared\Application\Bus\QueryBus;
use Shared\UI\Security\CsrfRejection;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Uid\Uuid;

/**
 * Signing in with a passkey (`POST /login/passkey`): CSRF first, the options this session was given (single use),
 * then AuthenticateWithPasskey checks the assertion under the second factor's algorithm and GetPasskeySignIn tells
 * the account it opened. The token needs no second factor (PasskeyToken). Every failure looks the same.
 */
final class PasskeyAuthenticator extends AbstractAuthenticator
{
    public const string CSRF_TOKEN_ID = 'passkey_login';

    public function __construct(
        private readonly PasskeyCeremony $ceremony,
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
        private readonly UserRepository $users,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly CsrfRejection $csrfRejection,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->isMethod('POST') && RouteCollection::LOGIN_PASSKEY->prefixed() === $request->attributes->get('_route');
    }

    public function authenticate(Request $request): Passport
    {
        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, (string) $request->request->get('_csrf_token')))) {
            throw new InvalidCsrfTokenException();
        }

        $session = $request->getSession();
        $options = $session->get(SessionKeys::PASSKEY_OPTIONS);
        $session->remove(SessionKeys::PASSKEY_OPTIONS);
        $response = (string) $request->request->get('_passkey');
        $claim = $this->ceremony->claimOf($response);
        if (!\is_string($options) || null === $claim) {
            throw new BadCredentialsException();
        }

        $attemptId = Uuid::v7()->toRfc4122();
        $this->commands->dispatch(new AuthenticateWithPasskey($claim->userId, $response, $attemptId, $options));
        $userId = $this->queries->ask(new GetPasskeySignIn($attemptId)) ?? throw new PasskeyNotAccepted();

        return new SelfValidatingPassport(new UserBadge($userId, function (string $id): SecurityUser {
            $user = $this->users->find(UserId::fromString($id));

            return null === $user ? throw new UserNotFoundException() : SecurityUser::of($user);
        }));
    }

    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        $user = $passport->getUser();

        return new PasskeyToken($user, $firewallName, $user->getRoles());
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        return new RedirectResponse($this->urls->generate('shared_home'), Response::HTTP_SEE_OTHER);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof InvalidCsrfTokenException) {
            return $this->csrfRejection->reject(self::CSRF_TOKEN_ID);
        }

        $request->getSession()->set(SessionKeys::AUTH_ERROR, 'identity.login.error.passkey');

        return new RedirectResponse($this->urls->generate(RouteCollection::LOGIN->prefixed()), Response::HTTP_SEE_OTHER);
    }
}
