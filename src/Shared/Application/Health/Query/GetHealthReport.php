<?php

declare(strict_types=1);

namespace Shared\Application\Health\Query;

use Shared\Application\Bus\Query;
use Shared\Domain\Health\HealthReport;

/**
 * @implements Query<HealthReport>
 */
final readonly class GetHealthReport implements Query
{
}
