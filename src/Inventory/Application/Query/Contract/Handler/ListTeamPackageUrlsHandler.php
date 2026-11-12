<?php

declare(strict_types=1);

namespace Inventory\Application\Query\Contract\Handler;

use Inventory\Application\Query\InventoryReadModel;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Inventory\ListTeamPackageUrls;

final readonly class ListTeamPackageUrlsHandler implements QueryHandler
{
    public function __construct(private InventoryReadModel $read)
    {
    }

    /**
     * @return list<string>
     */
    public function __invoke(ListTeamPackageUrls $query): array
    {
        return $this->read->teamPackageUrls($query->teamId);
    }
}
