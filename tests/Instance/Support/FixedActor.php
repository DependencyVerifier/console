<?php

declare(strict_types=1);

namespace Tests\Instance\Support;

use Shared\Application\Audit\Actor;
use Shared\Application\Security\CurrentActor;
use Symfony\Component\Uid\Uuid;

final readonly class FixedActor implements CurrentActor
{
    private function __construct(private bool $superAdmin)
    {
    }

    public static function superAdmin(): self
    {
        return new self(true);
    }

    /**
     * Any other user, a delegate holding instance permissions included: only the super admin flag counts.
     */
    public static function user(): self
    {
        return new self(false);
    }

    public function actor(): Actor
    {
        return Actor::user(Uuid::fromString('019a6b2c-5f00-7a11-8b22-3c44d55e6f01'));
    }

    public function isSuperAdmin(): bool
    {
        return $this->superAdmin;
    }
}
