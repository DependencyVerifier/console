<?php

declare(strict_types=1);

namespace Tests\Inventory\Support;

use Inventory\Domain\Model\HostInventory;
use Inventory\Domain\Repository\HostInventoryRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryHosts implements HostInventoryRepository
{
    /** @var array<string, HostInventory> */
    public array $hosts = [];

    public function find(Uuid $hostId): ?HostInventory
    {
        return $this->hosts[$hostId->toRfc4122()] ?? null;
    }

    public function add(HostInventory $host): void
    {
        $this->hosts[$host->hostId()->toRfc4122()] = $host;
    }

    public function deleteOfTeam(Uuid $teamId): void
    {
        $this->hosts = array_filter($this->hosts, static fn (HostInventory $h): bool => !$h->teamId()->equals($teamId));
    }
}
