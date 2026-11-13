<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Organization;

use Shared\Application\Bus\Query;

/**
 * Published query of Organization: the teams the actor is a member of, by name, for the home dashboard (a super admin
 * too: the instance views are in Administration).
 *
 * @implements Query<list<ActorTeam>>
 */
final readonly class ListActorTeams implements Query
{
}
