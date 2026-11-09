<?php

declare(strict_types=1);

namespace Organization\Application\Query\Project;

use Organization\Application\Query\View\MemberRow;
use Shared\Application\Bus\Query;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

/**
 * @implements Query<Page<MemberRow>>
 */
final readonly class ListProjectMembers implements Query
{
    public function __construct(public string $projectId, public PageRequest $page)
    {
    }
}
