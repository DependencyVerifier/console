<?php

declare(strict_types=1);

namespace Organization\Application\Query\Contract\Handler;

use Organization\Application\Query\OrganizationReadModel;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Organization\FindTeamNames;
use Symfony\Component\Uid\Uuid;

final readonly class FindTeamNamesHandler implements QueryHandler
{
    public function __construct(private OrganizationReadModel $read)
    {
    }

    /**
     * @return array<string, string>
     */
    public function __invoke(FindTeamNames $query): array
    {
        $ids = array_values(array_filter($query->teamIds, Uuid::isValid(...)));

        return [] === $ids ? [] : $this->read->teamNames($ids);
    }
}
