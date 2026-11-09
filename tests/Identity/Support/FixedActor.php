<?php

declare(strict_types=1);

namespace Tests\Identity\Support;

use Shared\Application\Audit\Actor;
use Shared\Application\Security\CurrentActor;
use Symfony\Component\Uid\Uuid;

final readonly class FixedActor implements CurrentActor
{
    public function __construct(private Actor $actor, private bool $superAdmin = false)
    {
    }

    public static function user(string $id = Users::ID): self
    {
        return new self(Actor::user(Uuid::fromString($id)));
    }

    public function actor(): Actor
    {
        return $this->actor;
    }

    public function isSuperAdmin(): bool
    {
        return $this->superAdmin;
    }
}
