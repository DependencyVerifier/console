<?php

declare(strict_types=1);

namespace Tests\Inventory\Support;

use Inventory\Domain\Model\SnapshotRecord;
use Inventory\Domain\Repository\SnapshotRecordRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryRecords implements SnapshotRecordRepository
{
    /** @var list<SnapshotRecord> */
    public array $records = [];

    /** @var list<array{string, string}> team, before */
    public array $purges = [];

    public function add(SnapshotRecord $record): void
    {
        $this->records[] = $record;
    }

    public function purgeBefore(Uuid $teamId, \DateTimeImmutable $before): int
    {
        $this->purges[] = [$teamId->toRfc4122(), $before->format(\DATE_ATOM)];

        return 0;
    }

    public function deleteOfTeam(Uuid $teamId): void
    {
        $this->records = array_values(array_filter($this->records, static fn (SnapshotRecord $r): bool => !$r->teamId()->equals($teamId)));
    }
}
