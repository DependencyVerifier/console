<?php

declare(strict_types=1);

namespace Inventory\Domain\Repository;

use Inventory\Domain\Model\InventoryRun;
use Symfony\Component\Uid\Uuid;

interface InventoryRunRepository
{
    public function find(Uuid $id): ?InventoryRun;

    public function add(InventoryRun $run): void;

    /**
     * Open inventories received before $before (to discard).
     *
     * @return list<InventoryRun>
     */
    public function openReceivedBefore(\DateTimeImmutable $before, int $limit): array;

    public function deleteOfTeam(Uuid $teamId): void;
}
