<?php

declare(strict_types=1);

namespace Identity\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * A passkey was removed from the account (by its holder or a super admin): every session is signed out.
 */
final readonly class PasskeyRevoked implements DomainEvent
{
    public function __construct(public string $userId)
    {
    }
}
