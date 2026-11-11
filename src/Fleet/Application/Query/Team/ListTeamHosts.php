<?php

declare(strict_types=1);

namespace Fleet\Application\Query\Team;

use Fleet\Application\Query\View\HostRow;
use Shared\Application\Bus\Query;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

/**
 * @implements Query<Page<HostRow>>
 */
final readonly class ListTeamHosts implements Query
{
    public function __construct(public string $teamId, public PageRequest $page = new PageRequest())
    {
    }
}
