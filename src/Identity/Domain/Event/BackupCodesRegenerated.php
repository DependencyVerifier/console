<?php

declare(strict_types=1);

namespace Identity\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * A new set of backup codes replaced the previous one.
 */
final readonly class BackupCodesRegenerated implements DomainEvent
{
    public function __construct(public string $userId)
    {
    }
}
