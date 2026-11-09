<?php

declare(strict_types=1);

namespace Organization\Application\Query\Project;

use Organization\Application\Query\View\ProjectView;
use Shared\Application\Bus\Query;

/**
 * @implements Query<ProjectView>
 */
final readonly class GetProject implements Query
{
    public function __construct(public string $projectId)
    {
    }
}
