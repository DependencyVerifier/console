<?php

declare(strict_types=1);

namespace Exposure\Application\Query\Handler;

use Exposure\Application\Query\ExposureFilter;
use Exposure\Application\Query\FindingReadModel;
use Exposure\Application\Query\GetProductionExposure;
use Exposure\Application\Query\View\ProductionExposure;
use Exposure\Application\Security\FindingAccess;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Organization\GetVisibleProjects;
use Shared\Application\Contract\Organization\ListTeamEnvironments;

final readonly class GetProductionExposureHandler implements QueryHandler
{
    public function __construct(private FindingAccess $access, private FindingReadModel $read, private QueryBus $queries, private ClockInterface $clock, private FindingPlaces $places)
    {
    }

    public function __invoke(GetProductionExposure $query): ProductionExposure
    {
        $scoped = $this->access->filter($query->teamId, $query->scope, $query->id);
        $filter = new ExposureFilter($scoped->teamId, $scoped->projectIds, $scoped->projectId, $scoped->environmentId, $scoped->hostId, true);
        $today = $this->clock->now();
        $visible = $this->queries->ask(new GetVisibleProjects($query->teamId));
        $hasProduction = false;
        foreach ($this->queries->ask(new ListTeamEnvironments($query->teamId)) as $environment) {
            $hasProduction = $hasProduction || ($environment->production && $visible->sees($environment->projectId));
        }

        return new ProductionExposure(
            $hasProduction,
            $this->read->summary($filter, $today),
            $this->places->rows($query->teamId, $this->read->urgent($filter, $today, $query->urgent)),
        );
    }
}
