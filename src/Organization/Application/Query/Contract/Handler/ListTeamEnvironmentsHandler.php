<?php

declare(strict_types=1);

namespace Organization\Application\Query\Contract\Handler;

use Organization\Application\Query\OrganizationReadModel;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Organization\ListTeamEnvironments;
use Shared\Application\Contract\Organization\TeamEnvironment;

final readonly class ListTeamEnvironmentsHandler implements QueryHandler
{
    public function __construct(private OrganizationReadModel $read)
    {
    }

    /**
     * @return list<TeamEnvironment>
     */
    public function __invoke(ListTeamEnvironments $query): array
    {
        return $this->read->teamEnvironments($query->teamId);
    }
}
