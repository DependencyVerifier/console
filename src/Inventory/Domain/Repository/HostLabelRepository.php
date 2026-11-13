<?php

declare(strict_types=1);

namespace Inventory\Domain\Repository;

use Inventory\Domain\Model\HostLabel;
use Symfony\Component\Uid\Uuid;

interface HostLabelRepository
{
    public function find(Uuid $hostId): ?HostLabel;

    public function add(HostLabel $label): void;

    public function deleteOfTeam(Uuid $teamId): void;
}
