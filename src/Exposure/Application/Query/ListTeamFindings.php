<?php

declare(strict_types=1);

namespace Exposure\Application\Query;

use Exposure\Application\Query\View\FindingRow;
use Shared\Application\Bus\Query;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

/**
 * The findings of a team, a project or an environment the actor sees, filtered.
 *
 * @implements Query<Page<FindingRow>>
 */
final readonly class ListTeamFindings implements Query
{
    public function __construct(
        public string $teamId,
        public ExposureScope $scope = ExposureScope::Team,
        public ?string $id = null,
        public FindingCriteria $criteria = new FindingCriteria(),
        public PageRequest $page = new PageRequest(),
    ) {
    }
}
