<?php

declare(strict_types=1);

namespace Identity\UI\Security;

use Identity\Infrastructure\Security\SessionKeys;
use Psr\Clock\ClockInterface;
use Shared\UI\Controller\RouteCollection as SharedRoutes;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

/**
 * Decision 8, once the account holder proved it is them (password and second factor, or a passkey): the time is
 * kept, the session id regenerated, and the user goes back to the stored route name (never a client-supplied URL).
 */
final readonly class ReauthenticationSuccess
{
    public function __construct(private RouterInterface $router, private ClockInterface $clock)
    {
    }

    public function respond(Request $request): Response
    {
        $session = $request->getSession();
        $session->set(SessionKeys::RECENT_AUTHENTICATION_AT, $this->clock->now()->getTimestamp());
        $session->migrate(true);

        $route = $session->get(SessionKeys::REAUTH_RETURN_ROUTE);
        $session->remove(SessionKeys::REAUTH_RETURN_ROUTE);
        $internal = \is_string($route) && null !== $this->router->getRouteCollection()->get($route) ? $route : SharedRoutes::HOME->prefixed();

        return new RedirectResponse($this->router->generate($internal), Response::HTTP_SEE_OTHER);
    }
}
