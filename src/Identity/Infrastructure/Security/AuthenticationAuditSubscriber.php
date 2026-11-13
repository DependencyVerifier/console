<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Security;

use Psr\Log\LoggerInterface;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Scheb\TwoFactorBundle\Security\Http\Authenticator\TwoFactorAuthenticator;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\AuditTrail;
use Shared\Application\Audit\Outcome;
use Shared\Application\Audit\Target;
use Shared\Infrastructure\Audit\AuditEntryFactory;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Authentication events of the audit log: password accepted (second factor required), login completed, login
 * failure (reason classified, the typed email is never stored), logout. Code verifications audit themselves and a
 * CSRF rejection is audited by CsrfRejection: neither is written twice here. Only LoginFailureEvent is listened to,
 * never scheb's FAILURE event (it does not carry the exception). If the audit write fails here, the error is logged
 * without blocking the login (accepted risk, docs/cadrage.md §7).
 */
final readonly class AuthenticationAuditSubscriber
{
    public function __construct(private AuditTrail $auditTrail, private AuditEntryFactory $entries, private LoggerInterface $logger)
    {
    }

    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getAuthenticatedToken()->getUser();
        $target = $user instanceof SecurityUser ? Target::of('user', $user->id) : Target::none();
        $action = $event->getAuthenticatedToken() instanceof TwoFactorTokenInterface ? 'identity.login_password' : 'identity.login';
        $this->record($action, $target, Outcome::success());
    }

    #[AsEventListener(event: LoginFailureEvent::class)]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $exception = $event->getException();
        if ($exception instanceof InvalidCsrfTokenException || $exception instanceof PasskeyNotAccepted || $event->getAuthenticator() instanceof TwoFactorAuthenticator) {
            return;
        }

        $outcome = match (true) {
            $exception instanceof TooManyLoginAttemptsAuthenticationException => Outcome::denied('throttled'),
            $exception instanceof AccountStatusException => Outcome::denied(AccountChecker::REASON),
            default => Outcome::failure('bad_credentials'),
        };
        $this->record('identity.login', Target::none(), $outcome);
    }

    #[AsEventListener(event: LogoutEvent::class, priority: 64)]
    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        if ($user instanceof SecurityUser) {
            $this->record('identity.logout', Target::of('user', $user->id), Outcome::success());
        }
    }

    private function record(string $action, Target $target, Outcome $outcome): void
    {
        try {
            $this->auditTrail->record($this->entries->create(AuditAction::named($action), $target, $outcome, AuditContext::empty()));
        } catch (\Throwable $failure) {
            $this->logger->error('An authentication event could not be audited.', ['exception_class' => $failure::class]);
        }
    }
}
