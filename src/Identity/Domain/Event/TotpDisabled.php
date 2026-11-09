<?php

declare(strict_types=1);

namespace Identity\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * TOTP turned off by its owner: the email code is the second factor again.
 */
final readonly class TotpDisabled implements DomainEvent
{
    public function __construct(public string $userId)
    {
    }
}
