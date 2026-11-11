<?php

declare(strict_types=1);

namespace Tests\Inventory\Unit\Application;

use Inventory\Application\Content\InventoryTeamContent;
use Inventory\Domain\Model\AppInstance;
use Inventory\Domain\Model\ContainerImage;
use Inventory\Domain\Model\HostInventory;
use Inventory\Domain\Model\MediaType;
use Inventory\Domain\Model\SnapshotBlob;
use Inventory\Domain\Model\SnapshotRecord;
use Inventory\Domain\Model\SnapshotSubject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Tests\Inventory\Support\InMemoryApps;
use Tests\Inventory\Support\InMemoryBlobs;
use Tests\Inventory\Support\InMemoryHosts;
use Tests\Inventory\Support\InMemoryImages;
use Tests\Inventory\Support\InMemoryRecords;
use Tests\Inventory\Support\InMemoryRuns;
use Tests\Inventory\Support\Inv;

final class TeamContentTest extends TestCase
{
    #[Test]
    public function it_should_erase_the_inventory_of_a_deleted_team_and_guard_environments_with_applications(): void
    {
        [$runs, $records, $apps, $images, $hosts, $blobs] = [new InMemoryRuns(), new InMemoryRecords(), new InMemoryApps(), new InMemoryImages(), new InMemoryHosts(), new InMemoryBlobs()];
        $team = Uuid::fromString(Inv::TEAM);
        $other = Uuid::fromString(Inv::OTHER_TEAM);
        $runs->add(Inv::run());
        $runs->add(Inv::run(id: '019a6b31-5e00-7f12-a3b4-c5d6e7f80913', teamId: Inv::OTHER_TEAM));
        $records->add(SnapshotRecord::take(Uuid::v7(), $team, Uuid::fromString(Inv::RUN), SnapshotSubject::Image, Uuid::v7(), Inv::digest(1), Inv::now()));
        $app = AppInstance::discover(Uuid::fromString(Inv::APP), $team, Uuid::fromString(Inv::HOST), Uuid::fromString(Inv::AGENT), Inv::path(), Inv::now());
        $app->assign(Uuid::fromString(Inv::ENVIRONMENT));
        $apps->add($app);
        $images->add(ContainerImage::discover(Uuid::v7(), $team, Inv::image(), Inv::now()));
        $hosts->add(HostInventory::of(Uuid::fromString(Inv::HOST), $team));
        $blobs->add(SnapshotBlob::store(Uuid::v7(), $team, Inv::digest(1), MediaType::CycloneDx, 1, 'gz', Inv::now()));
        $blobs->add(SnapshotBlob::store(Uuid::v7(), $other, Inv::digest(1), MediaType::CycloneDx, 1, 'gz', Inv::now()));
        $content = new InventoryTeamContent($runs, $records, $apps, $images, $hosts, $blobs);

        self::assertTrue($content->environmentHasContent(Inv::ENVIRONMENT));
        self::assertFalse($content->environmentHasContent(Inv::TEAM));
        self::assertFalse($content->environmentHasContent('not-a-uuid'));
        self::assertFalse($content->projectHasContent(Inv::ENVIRONMENT));

        $content->eraseTeam(Inv::TEAM);
        self::assertSame([[], [], [], [], ['019a6b31-5e00-7f12-a3b4-c5d6e7f80913']], [$records->records, $apps->apps, $images->images, $hosts->hosts, array_keys($runs->runs)]);
        self::assertCount(1, $blobs->blobs);
        self::assertFalse($content->environmentHasContent(Inv::ENVIRONMENT));
    }
}
