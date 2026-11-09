<?php

declare(strict_types=1);

namespace Identity\UI\Security;

use Identity\Infrastructure\Security\SessionKeys;
use Identity\UI\Controller\RouteCollection;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsEventListener]
final readonly class RecentAuthenticationListener
{
    public const int MAX_AGE_SECONDS = 600;

    public function __construct(private ClockInterface $clock, private UrlGeneratorInterface $urls)
    {
    }

    public function __invoke(ControllerArgumentsEvent $event): void
    {
        $attributes = $event->getAttributes(RequiresRecentAuthentication::class);
        if ([] === $attributes) {
            return;
        }

        $request = $event->getRequest();
        $session = $request->getSession();
        $at = $session->get(SessionKeys::RECENT_AUTHENTICATION_AT);
        if (\is_int($at) && $this->clock->now()->getTimestamp() - $at <= self::MAX_AGE_SECONDS) {
            return;
        }

        $session->set(SessionKeys::REAUTH_RETURN_ROUTE, $attributes[0]->returnRoute);
        $event->setController(fn (): RedirectResponse => new RedirectResponse($this->urls->generate(RouteCollection::REAUTHENTICATE->prefixed())));
    }
}
