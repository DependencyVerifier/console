<?php

declare(strict_types=1);

namespace Organization\Application\Query\Contract\Handler;

use Organization\Application\Query\OrganizationReadModel;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Organization\FindTeamEnvironment;
use Shared\Application\Contract\Organization\TeamEnvironment;
use Symfony\Component\Uid\Uuid;

final readonly class FindTeamEnvironmentHandler implements QueryHandler
{
    public function __construct(private OrganizationReadModel $read)
    {
    }

    public function __invoke(FindTeamEnvironment $query): ?TeamEnvironment
    {
        if (!Uuid::isValid($query->environmentId)) {
            return null;
        }

        return $this->read->teamEnvironments($query->teamId, $query->environmentId)[0] ?? null;
    }
}
