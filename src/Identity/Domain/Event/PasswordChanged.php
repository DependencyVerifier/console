<?php

declare(strict_types=1);

namespace Identity\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * The password changed (by its owner or through a reset link).
 */
final readonly class PasswordChanged implements DomainEvent
{
    public function __construct(public string $userId)
    {
    }
}
