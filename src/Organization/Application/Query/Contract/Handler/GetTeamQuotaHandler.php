<?php

declare(strict_types=1);

namespace Organization\Application\Query\Contract\Handler;

use Organization\Domain\Model\QuotaName;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Repository\TeamRepository;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Organization\GetTeamQuota;

final readonly class GetTeamQuotaHandler implements QueryHandler
{
    public function __construct(private TeamRepository $teams)
    {
    }

    public function __invoke(GetTeamQuota $query): ?int
    {
        $team = $this->teams->find(TeamId::fromString($query->teamId)) ?? throw ResourceNotFound::of('team');

        return $team->quotas()->get(QuotaName::from($query->quota))->toStorage();
    }
}
