<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Inventory;

use Shared\Application\Bus\Query;

/**
 * Published query of Inventory: what each application still found and each inventoried host of a team runs. An
 * application counts the components of its image; a host its OS packages, runtimes and OS.
 *
 * @implements Query<list<TeamSubject>>
 */
final readonly class ListTeamSubjects implements Query
{
    public function __construct(public string $teamId)
    {
    }
}
