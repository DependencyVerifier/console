<?php

declare(strict_types=1);

namespace Organization\Application\Query\Project;

use Organization\Application\Query\View\ProjectRow;
use Shared\Application\Bus\Query;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

/**
 * Projects of a team the actor may see (a TEAM_GUEST only sees the projects where they have a role).
 *
 * @implements Query<Page<ProjectRow>>
 */
final readonly class ListProjects implements Query
{
    public function __construct(public string $teamId, public PageRequest $page)
    {
    }
}
