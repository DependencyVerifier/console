<?php

declare(strict_types=1);

namespace Inventory\Domain\Repository;

use Inventory\Domain\Model\SnapshotRecord;
use Symfony\Component\Uid\Uuid;

interface SnapshotRecordRepository
{
    public function add(SnapshotRecord $record): void;

    /**
     * History entries of the team taken before $before, except the latest of each subject.
     *
     * @return int the entries deleted
     */
    public function purgeBefore(Uuid $teamId, \DateTimeImmutable $before): int;

    public function deleteOfTeam(Uuid $teamId): void;
}
