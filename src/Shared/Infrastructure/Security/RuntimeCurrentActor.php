<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Security;

use Shared\Application\Audit\Actor;
use Shared\Application\Security\CurrentActor;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Actor without authentication: `cli` outside a request, `anonymous` inside one. Identity decorates it with the
 * authenticated user once the firewall exists.
 */
final readonly class RuntimeCurrentActor implements CurrentActor
{
    public function __construct(private RequestStack $requests)
    {
    }

    public function actor(): Actor
    {
        return null === $this->requests->getMainRequest() ? Actor::cli() : Actor::anonymous();
    }

    public function isSuperAdmin(): bool
    {
        return false;
    }
}
