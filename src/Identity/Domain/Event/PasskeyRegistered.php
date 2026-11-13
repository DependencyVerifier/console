<?php

declare(strict_types=1);

namespace Identity\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * A passkey was added to the account.
 */
final readonly class PasskeyRegistered implements DomainEvent
{
    public function __construct(public string $userId)
    {
    }
}
