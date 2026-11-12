<?php

declare(strict_types=1);

namespace Exposure\Application\Query;

use Exposure\Application\Query\View\FindingView;
use Shared\Application\Bus\Query;

/**
 * @implements Query<FindingView>
 */
final readonly class GetFinding implements Query
{
    public function __construct(public string $teamId, public string $findingId)
    {
    }
}
