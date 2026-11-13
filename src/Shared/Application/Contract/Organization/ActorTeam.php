<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Organization;

/**
 * A team of the actor, with its role there (`team_admin`, `team_member`, `team_viewer`, `team_guest`).
 */
final readonly class ActorTeam
{
    public function __construct(public string $teamId, public string $name, public string $role)
    {
    }
}
