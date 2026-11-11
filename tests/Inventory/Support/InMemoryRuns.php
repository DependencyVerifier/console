<?php

declare(strict_types=1);

namespace Tests\Inventory\Support;

use Inventory\Domain\Model\InventoryRun;
use Inventory\Domain\Model\RunStatus;
use Inventory\Domain\Repository\InventoryRunRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryRuns implements InventoryRunRepository
{
    /** @var array<string, InventoryRun> */
    public array $runs = [];

    public function find(Uuid $id): ?InventoryRun
    {
        return $this->runs[$id->toRfc4122()] ?? null;
    }

    public function add(InventoryRun $run): void
    {
        $this->runs[$run->id()->toRfc4122()] = $run;
    }

    public function openReceivedBefore(\DateTimeImmutable $before, int $limit): array
    {
        return \array_slice(array_values(array_filter($this->runs, static fn (InventoryRun $r): bool => RunStatus::Open === $r->status() && $r->receivedAt() < $before)), 0, $limit);
    }

    public function deleteOfTeam(Uuid $teamId): void
    {
        $this->runs = array_filter($this->runs, static fn (InventoryRun $r): bool => !$r->teamId()->equals($teamId));
    }
}
