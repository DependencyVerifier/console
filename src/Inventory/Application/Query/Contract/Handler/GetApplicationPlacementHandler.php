<?php

declare(strict_types=1);

namespace Inventory\Application\Query\Contract\Handler;

use Inventory\Application\Query\InventoryReadModel;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Inventory\GetApplicationPlacement;
use Symfony\Component\Uid\Uuid;

final readonly class GetApplicationPlacementHandler implements QueryHandler
{
    public function __construct(private InventoryReadModel $read)
    {
    }

    /**
     * @return ?array{teamId: string, environmentId: ?string}
     */
    public function __invoke(GetApplicationPlacement $query): ?array
    {
        $app = Uuid::isValid($query->applicationId) ? $this->read->application($query->applicationId) : null;

        return null === $app ? null : ['teamId' => $app->teamId, 'environmentId' => $app->environmentId];
    }
}
