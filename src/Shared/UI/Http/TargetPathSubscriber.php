<?php

declare(strict_types=1);

namespace Shared\UI\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function Symfony\Component\String\u;

/**
 * A Turbo Frame request (or a `…_frame` route) is never the page to come back to after the login: the firewall
 * would otherwise save the frame URL as target path, and the user would land on a bare fragment.
 */
#[AsEventListener(KernelEvents::RESPONSE, priority: -16)]
final class TargetPathSubscriber
{
    public const string SESSION_KEY = '_security.main.target_path';

    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasSession() || !$request->getSession()->isStarted()) {
            return;
        }

        $route = $request->attributes->get('_route');
        if ($request->headers->has('Turbo-Frame') || (\is_string($route) && u($route)->endsWith('_frame'))) {
            $request->getSession()->remove(self::SESSION_KEY);
        }
    }
}
