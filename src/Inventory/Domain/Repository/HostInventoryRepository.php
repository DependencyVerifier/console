<?php

declare(strict_types=1);

namespace Inventory\Domain\Repository;

use Inventory\Domain\Model\HostInventory;
use Symfony\Component\Uid\Uuid;

interface HostInventoryRepository
{
    public function find(Uuid $hostId): ?HostInventory;

    public function add(HostInventory $host): void;

    public function deleteOfTeam(Uuid $teamId): void;
}
