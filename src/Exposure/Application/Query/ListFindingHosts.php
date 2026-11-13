<?php

declare(strict_types=1);

namespace Exposure\Application\Query;

use Shared\Application\Bus\Query;

/**
 * The hosts of the findings an actor sees in a perimeter, for the Host filter of a list.
 *
 * @implements Query<array<string, string>>
 */
final readonly class ListFindingHosts implements Query
{
    public function __construct(public string $teamId, public ExposureScope $scope = ExposureScope::Team, public ?string $id = null)
    {
    }
}
