<?php

declare(strict_types=1);

namespace Exposure\Application\Query;

use Exposure\Application\Query\View\ExposureSummary;
use Shared\Application\Bus\Query;

/**
 * The figures of a team, a project, an environment or a host, as far as the actor sees them.
 *
 * @implements Query<ExposureSummary>
 */
final readonly class GetExposureSummary implements Query
{
    public function __construct(public string $teamId, public ExposureScope $scope = ExposureScope::Team, public ?string $id = null)
    {
    }
}
