<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Security;

use Psr\Clock\ClockInterface;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Decision 7: 2 hours of inactivity, 12 hours in total, enforced here with the clock (testable with MockClock);
 * the session garbage collector only cleans up. Runs before the firewall reads the token from the session.
 * Also records the time of the last full authentication, read by the recent authentication check (10 minutes).
 */
final readonly class SessionLifetimeSubscriber
{
    public const int IDLE_SECONDS = 7200;
    public const int ABSOLUTE_SECONDS = 43_200;

    public function __construct(private ClockInterface $clock)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 9)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasPreviousSession()) {
            return;
        }

        $session = $request->getSession();
        $authenticatedAt = $session->get(SessionKeys::AUTHENTICATED_AT);
        if (!\is_int($authenticatedAt)) {
            return;
        }

        $now = $this->clock->now()->getTimestamp();
        $lastActivity = $session->get(SessionKeys::LAST_ACTIVITY, $authenticatedAt);
        if (!\is_int($lastActivity) || $now - $lastActivity > self::IDLE_SECONDS || $now - $authenticatedAt > self::ABSOLUTE_SECONDS) {
            $session->invalidate();

            return;
        }

        $session->set(SessionKeys::LAST_ACTIVITY, $now);
    }

    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $session = $event->getRequest()->getSession();
        $now = $this->clock->now()->getTimestamp();
        if ($event->getAuthenticatedToken() instanceof TwoFactorTokenInterface || !\is_int($session->get(SessionKeys::AUTHENTICATED_AT))) {
            $session->set(SessionKeys::AUTHENTICATED_AT, $now);
        }

        $session->set(SessionKeys::LAST_ACTIVITY, $now);
        if (!$event->getAuthenticatedToken() instanceof TwoFactorTokenInterface) {
            $session->set(SessionKeys::RECENT_AUTHENTICATION_AT, $now);
        }
    }
}
