<?php

declare(strict_types=1);

namespace Identity\UI\Controller\Security;

use Identity\UI\Controller\RouteCollection;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST only, with a CSRF token (`logout`): intercepted by the firewall, never reached.
 */
#[Route(path: '/logout', name: RouteCollection::LOGOUT->value, methods: ['POST'])]
final class LogoutController
{
    public function __invoke(): never
    {
        throw new \LogicException('Intercepted by the firewall logout.');
    }
}
