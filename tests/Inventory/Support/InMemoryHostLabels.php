<?php

declare(strict_types=1);

namespace Tests\Inventory\Support;

use Inventory\Domain\Model\HostLabel;
use Inventory\Domain\Repository\HostLabelRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryHostLabels implements HostLabelRepository
{
    /** @var array<string, HostLabel> */
    public array $labels = [];

    public function find(Uuid $hostId): ?HostLabel
    {
        return $this->labels[$hostId->toRfc4122()] ?? null;
    }

    public function add(HostLabel $label): void
    {
        $this->labels[$label->hostId()->toRfc4122()] = $label;
    }

    public function deleteOfTeam(Uuid $teamId): void
    {
        $this->labels = array_filter($this->labels, static fn (HostLabel $l): bool => !$l->teamId()->equals($teamId));
    }
}
