<?php

declare(strict_types=1);

namespace Exposure\Application\Query;

use Exposure\Application\Query\View\FindingRow;
use Shared\Application\Bus\Query;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

/**
 * The findings of an application or a host the actor sees.
 *
 * @implements Query<Page<FindingRow>>
 */
final readonly class ListFindings implements Query
{
    public function __construct(public string $teamId, public string $subjectType, public string $subjectId, public PageRequest $page = new PageRequest())
    {
    }
}
