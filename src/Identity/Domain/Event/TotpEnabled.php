<?php

declare(strict_types=1);

namespace Identity\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * TOTP enrolment confirmed: the authenticator app is now the second factor.
 */
final readonly class TotpEnabled implements DomainEvent
{
    public function __construct(public string $userId)
    {
    }
}
