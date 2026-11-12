<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Inventory;

use Shared\Application\Bus\Query;

/**
 * Published query of Inventory: the package URLs a team runs now (the OS packages of its hosts, the components of
 * its applications still found and of its images), each once.
 *
 * @implements Query<list<string>>
 */
final readonly class ListTeamPackageUrls implements Query
{
    public function __construct(public string $teamId)
    {
    }
}
