<?php

declare(strict_types=1);

namespace Exposure\Application\Query;

/**
 * What of a team's findings an actor may read in a perimeter, for the read model: `projectIds` restricts a
 * TEAM_GUEST to its projects (never the fleet), null means every finding of the team; `production` keeps the findings
 * in production only (the production dashboards).
 */
final readonly class ExposureFilter
{
    /**
     * @param ?list<string> $projectIds
     */
    public function __construct(
        public string $teamId,
        public ?array $projectIds = null,
        public ?string $projectId = null,
        public ?string $environmentId = null,
        public ?string $hostId = null,
        public bool $production = false,
    ) {
    }
}
