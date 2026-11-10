<?php

declare(strict_types=1);

namespace Organization\Domain\Event;

use Shared\Domain\Event\DomainEvent;

/**
 * An account was made TEAM_ADMIN when the team was created (no invitation to accept): the holder is told.
 */
final readonly class TeamAdminAppointed implements DomainEvent
{
    public function __construct(public string $teamId, public string $userId, public string $teamName)
    {
    }
}
