<?php

declare(strict_types=1);

namespace Exposure\Application\Query;

use Exposure\Application\Query\View\Portfolio;
use Shared\Application\Bus\Query;

/**
 * The home dashboard: what is open in production in every team of the actor, project by project, and the most urgent
 * findings of them all.
 *
 * @implements Query<Portfolio>
 */
final readonly class GetPortfolio implements Query
{
    public const int URGENT = 10;
}
