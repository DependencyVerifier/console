<?php

declare(strict_types=1);

namespace Tests\Inventory\Unit\Application;

use Inventory\Application\Command\Maintenance\Handler\MaintainInventoryHandler;
use Inventory\Application\Command\Maintenance\MaintainInventory;
use Inventory\Domain\Model\RunStatus;
use Inventory\Domain\Model\SnapshotRecord;
use Inventory\Domain\Model\SnapshotSubject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Tests\Inventory\Support\Inv;
use Tests\Inventory\Support\InventoryWorld;

final class MaintenanceTest extends TestCase
{
    #[Test]
    public function it_should_discard_stale_inventories_purge_history_and_collect_snapshots(): void
    {
        $world = new InventoryWorld();
        $stale = Inv::run();
        $fresh = Inv::run(id: '019a6b31-5e00-7f12-a3b4-c5d6e7f80913');
        $alsoStale = Inv::run(id: '019a6b31-5e00-7f12-a3b4-c5d6e7f80914');
        $world->runs->add($stale);
        $world->runs->add($fresh);
        $world->runs->add($alsoStale);
        (fn () => $this->receivedAt = new \DateTimeImmutable('2026-11-09 07:00:00'))->call($alsoStale);
        $world->records->add(SnapshotRecord::take(Uuid::v7(), Uuid::fromString(Inv::TEAM), Uuid::fromString(Inv::RUN), SnapshotSubject::Image, Uuid::v7(), Inv::digest(1), Inv::now()));
        $world->records->add(SnapshotRecord::take(Uuid::v7(), Uuid::fromString(Inv::OTHER_TEAM), Uuid::fromString(Inv::RUN), SnapshotSubject::Image, Uuid::v7(), Inv::digest(1), Inv::now()));
        $world->limits[Inv::TEAM.' snapshot_retention_days'] = 90;
        $world->limits[Inv::OTHER_TEAM.' snapshot_retention_days'] = 30;
        $world->records->purged = 3;
        $world->blobs->collected = 4;
        (fn () => $this->receivedAt = new \DateTimeImmutable('2026-11-09 07:20:00'))->call($stale);
        $world->clock->modify('+59 minutes');

        $command = new MaintainInventory(batch: 10);
        $this->handler($world)($command);
        self::assertSame([RunStatus::Discarded, RunStatus::Open], [$stale->status(), $fresh->status()], 'Received an hour ago or more only.');
        self::assertSame([2, 6, 4], [$command->report->discarded, $command->report->purgedHistory, $command->report->collectedBlobs], 'Summed over every inventory and team.');
        self::assertSame([[Inv::TEAM, '2026-08-11T09:01:00+00:00'], [Inv::OTHER_TEAM, '2026-10-10T09:01:00+00:00']], $world->records->purges);
        $again = new InventoryWorld();
        $again->records->records = $world->records->records;
        $again->limits[Inv::TEAM.' snapshot_retention_days'] = 90;
        $this->handler($again)(new MaintainInventory());
        self::assertSame([Inv::TEAM], array_column($again->records->purges, 0), 'An unlimited retention purges nothing.');

        $command = new MaintainInventory(batch: 10);
        $this->handler($world)($command);
        self::assertSame(0, $command->report->discarded);
        self::assertSame(['inventory.maintain', 500, []], [new MaintainInventory()->auditAction()->name, new MaintainInventory()->batch, $command->auditContext()->values]);
        self::assertTrue($command->auditTarget()->isNone());
    }

    private function handler(InventoryWorld $world): MaintainInventoryHandler
    {
        return new MaintainInventoryHandler($world->runs, $world->records, $world->blobs, $world->queries, $world->clock);
    }
}
