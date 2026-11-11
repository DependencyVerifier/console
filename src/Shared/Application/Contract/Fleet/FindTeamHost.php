<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Fleet;

use Shared\Application\Bus\Query;

/**
 * Published query of Fleet: the hostname of a host, only if it belongs to that team (null otherwise). The caller
 * already decided the actor may see it.
 *
 * @implements Query<string|null>
 */
final readonly class FindTeamHost implements Query
{
    public function __construct(public string $teamId, public string $hostId)
    {
    }
}
