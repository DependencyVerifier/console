<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Security;

use Identity\Application\Command\SecondFactor\IssueEmailLoginCode;
use Identity\Application\Command\SecondFactor\UseBackupCode;
use Identity\Application\Command\SecondFactor\VerifyEmailLoginCode;
use Identity\Application\Command\SecondFactor\VerifyTotp;
use Identity\Application\SecondFactor\CodeVerification;
use Identity\Infrastructure\Security\SecondFactorThrottledException;
use Identity\Infrastructure\Security\SecurityUser;
use Identity\Infrastructure\Security\SessionKeys;
use Identity\Infrastructure\Security\TwoFactor\CodeVerifyingProvider;
use Identity\UI\Controller\RouteCollection;
use Identity\UI\Form\ReauthenticateType;
use Psr\Clock\ClockInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Trusted\TrustedDeviceManagerInterface;
use Shared\Application\Audit\AlreadyAudited;
use Shared\Application\Bus\CommandBus;
use Shared\Application\Security\AttemptThrottle;
use Shared\Application\Security\ThrottleName;
use Shared\Application\Security\ThrottleSubject;
use Shared\UI\Controller\RouteCollection as SharedRoutes;
use Shared\UI\Security\CsrfFailure;
use Shared\UI\Security\CsrfRejection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\String\u;

/**
 * Decision 8: the password, then the second factor unless the device is trusted. Wrong passwords count in the
 * second factor windows. On success the session id is regenerated and the user goes back to the stored route
 * name (never a client-supplied URL).
 */
#[Route(path: '/reauthenticate', name: RouteCollection::REAUTHENTICATE->value, methods: ['GET', 'POST'])]
final class ReauthenticateController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly CodeVerifyingProvider $codes,
        private readonly AttemptThrottle $throttle,
        private readonly UserPasswordHasherInterface $passwords,
        #[Autowire(service: 'scheb_two_factor.trusted_device_manager')]
        private readonly TrustedDeviceManagerInterface $trustedDevices,
        private readonly CsrfRejection $csrfRejection,
        private readonly RouterInterface $router,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request, #[CurrentUser] SecurityUser $user): Response
    {
        $withCode = !$this->trustedDevices->isTrustedDevice($user, 'main');
        $form = $this->createForm(ReauthenticateType::class, null, ['with_code' => $withCode]);
        $form->handleRequest($request);
        $csrf = CsrfFailure::tokenIdOf($form);
        if (null !== $csrf) {
            return $this->csrfRejection->reject($csrf);
        }

        if (!$form->isSubmitted() && $withCode && !$user->isTotpActive()) {
            try {
                $this->commands->dispatch(new IssueEmailLoginCode($user->id, Uuid::v7()->toRfc4122()));
            } catch (AlreadyAudited) {
                // Throttled: the code sent earlier stays valid.
            }
        }

        $status = Response::HTTP_OK;
        if ($form->isSubmitted()) {
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            if ($form->isValid()) {
                /** @var array{password: string, code?: ?string} $data */
                $data = $form->getData();
                $error = $this->check($user, $data['password'], $withCode ? (string) ($data['code'] ?? '') : null);
                if (null === $error) {
                    return $this->succeed($request);
                }

                $form->addError(new FormError($error));
                $status = 'identity.login.error.throttled' === $error ? Response::HTTP_TOO_MANY_REQUESTS : Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        $response = $this->render('identity/security/reauthenticate.html.twig', [
            'form' => $form,
            'with_code' => $withCode,
            'totp' => $user->isTotpActive(),
        ], new Response(status: $status));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function check(SecurityUser $user, #[\SensitiveParameter] string $password, #[\SensitiveParameter] ?string $code): ?string
    {
        $account = ThrottleSubject::forAccount($user->id);
        foreach ([ThrottleName::SecondFactorShort, ThrottleName::SecondFactorLong] as $window) {
            if (!$this->throttle->peek($window, $account)->accepted) {
                return 'identity.login.error.throttled';
            }
        }

        if (!$this->passwords->isPasswordValid($user, $password)) {
            $this->throttle->consume(ThrottleName::SecondFactorShort, $account);
            $this->throttle->consume(ThrottleName::SecondFactorLong, $account);

            return 'identity.reauthenticate.error.invalid';
        }

        if (null === $code) {
            return null;
        }

        try {
            return $this->codes->verify($this->verification($user, $code)) ? null : 'identity.reauthenticate.error.invalid';
        } catch (SecondFactorThrottledException) {
            return 'identity.login.error.throttled';
        }
    }

    private function verification(SecurityUser $user, #[\SensitiveParameter] string $code): CodeVerification
    {
        $attemptId = Uuid::v7()->toRfc4122();
        if (!$user->isTotpActive()) {
            return new VerifyEmailLoginCode($user->id, $code, $attemptId);
        }

        return [] !== u($code)->trim()->lower()->match('/^[a-z0-9]{5}-[a-z0-9]{5}$/')
            ? new UseBackupCode($user->id, $code, $attemptId)
            : new VerifyTotp($user->id, $code, $attemptId);
    }

    private function succeed(Request $request): Response
    {
        $session = $request->getSession();
        $session->set(SessionKeys::RECENT_AUTHENTICATION_AT, $this->clock->now()->getTimestamp());
        $session->migrate(true);

        $route = $session->get(SessionKeys::REAUTH_RETURN_ROUTE);
        $session->remove(SessionKeys::REAUTH_RETURN_ROUTE);
        $internal = \is_string($route) && null !== $this->router->getRouteCollection()->get($route) ? $route : SharedRoutes::HOME->prefixed();

        return $this->redirectToRoute($internal, status: Response::HTTP_SEE_OTHER);
    }
}
