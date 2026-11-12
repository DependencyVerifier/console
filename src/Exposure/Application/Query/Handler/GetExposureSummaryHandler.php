<?php

declare(strict_types=1);

namespace Exposure\Application\Query\Handler;

use Exposure\Application\Query\FindingReadModel;
use Exposure\Application\Query\GetExposureSummary;
use Exposure\Application\Query\View\ExposureSummary;
use Exposure\Application\Security\FindingAccess;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\QueryHandler;

final readonly class GetExposureSummaryHandler implements QueryHandler
{
    public function __construct(private FindingAccess $access, private FindingReadModel $read, private ClockInterface $clock)
    {
    }

    public function __invoke(GetExposureSummary $query): ExposureSummary
    {
        return $this->read->summary($this->access->filter($query->teamId, $query->scope, $query->id), $this->clock->now());
    }
}
