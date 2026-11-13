<?php

declare(strict_types=1);

namespace Inventory\Application\Query\Team;

use Inventory\Application\Query\View\MatrixRow;
use Shared\Application\Bus\Query;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

/**
 * The components of an environment (cadrage §5bis): which version of each runtime, OS and direct dependency runs on
 * which application and host, a component with several versions flagged as a drift.
 *
 * @implements Query<Page<MatrixRow>>
 */
final readonly class GetEnvironmentMatrix implements Query
{
    public function __construct(public string $teamId, public string $environmentId, public PageRequest $page = new PageRequest(), public bool $driftOnly = false)
    {
    }
}
