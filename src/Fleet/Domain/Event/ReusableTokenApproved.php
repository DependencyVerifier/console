<?php

declare(strict_types=1);

namespace Fleet\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * A reusable token was approved: its next enrollments give approved agents.
 */
final readonly class ReusableTokenApproved implements DomainEvent
{
    public function __construct(public string $tokenId, public string $teamId)
    {
    }
}
