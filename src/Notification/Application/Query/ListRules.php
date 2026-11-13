<?php

declare(strict_types=1);

namespace Notification\Application\Query;

use Notification\Application\Query\View\RuleRow;
use Shared\Application\Bus\Query;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

/**
 * The rules of a project, or of the fleet (no project), the oldest first: seen by whoever sees the scope.
 *
 * @implements Query<Page<RuleRow>>
 */
final readonly class ListRules implements Query
{
    public function __construct(public string $teamId, public ?string $projectId, public PageRequest $page)
    {
    }
}
