<?php

declare(strict_types=1);

namespace Inventory\Application\Query\Contract\Handler;

use Inventory\Application\Query\InventoryReadModel;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Inventory\ListTeamPackages;

final readonly class ListTeamPackagesHandler implements QueryHandler
{
    public function __construct(private InventoryReadModel $read)
    {
    }

    /**
     * @return list<array{purl: string, direct: bool}>
     */
    public function __invoke(ListTeamPackages $query): array
    {
        return $this->read->teamPackages($query->teamId);
    }
}
