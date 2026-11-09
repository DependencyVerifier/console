<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Identity;

use Shared\Domain\Event\DomainEvent;

/**
 * Published language of Identity: an account was deactivated. Organization suspends its memberships.
 */
final readonly class AccountDeactivated implements DomainEvent
{
    public function __construct(public string $userId)
    {
    }
}
