<?php

declare(strict_types=1);

namespace Inventory\Application\Query\Contract\Handler;

use Inventory\Application\Query\InventoryReadModel;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Inventory\ListTeamSubjects;
use Shared\Application\Contract\Inventory\TeamSubject;

final readonly class ListTeamSubjectsHandler implements QueryHandler
{
    public function __construct(private InventoryReadModel $read)
    {
    }

    /**
     * @return list<TeamSubject>
     */
    public function __invoke(ListTeamSubjects $query): array
    {
        return $this->read->teamSubjects($query->teamId);
    }
}
