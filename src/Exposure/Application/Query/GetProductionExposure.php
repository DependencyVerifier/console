<?php

declare(strict_types=1);

namespace Exposure\Application\Query;

use Exposure\Application\Query\View\ProductionExposure;
use Shared\Application\Bus\Query;

/**
 * What is open in production in a perimeter the actor sees: the figures and the most urgent findings.
 *
 * @implements Query<ProductionExposure>
 */
final readonly class GetProductionExposure implements Query
{
    public const int URGENT = 5;

    public function __construct(public string $teamId, public ExposureScope $scope = ExposureScope::Team, public ?string $id = null, public int $urgent = self::URGENT)
    {
    }
}
