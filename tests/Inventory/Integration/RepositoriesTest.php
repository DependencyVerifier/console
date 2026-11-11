<?php

declare(strict_types=1);

namespace Tests\Inventory\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Inventory\Application\Content\InventoryTeamContent;
use Inventory\Domain\Model\AppInstance;
use Inventory\Domain\Model\ContainerImage;
use Inventory\Domain\Model\HostInventory;
use Inventory\Domain\Model\MediaType;
use Inventory\Domain\Model\Package;
use Inventory\Domain\Model\Purl;
use Inventory\Domain\Model\SnapshotBlob;
use Inventory\Domain\Model\SnapshotRecord;
use Inventory\Domain\Model\SnapshotSubject;
use Inventory\Infrastructure\Doctrine\DbalPackageRepository;
use Inventory\Infrastructure\Doctrine\DoctrineAppInstanceRepository;
use Inventory\Infrastructure\Doctrine\DoctrineContainerImageRepository;
use Inventory\Infrastructure\Doctrine\DoctrineHostInventoryRepository;
use Inventory\Infrastructure\Doctrine\DoctrineInventoryRunRepository;
use Inventory\Infrastructure\Doctrine\DoctrineSnapshotBlobRepository;
use Inventory\Infrastructure\Doctrine\DoctrineSnapshotRecordRepository;
use Inventory\Infrastructure\Doctrine\InventoryManager;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Tests\Inventory\Support\Inv;
use Tests\Organization\Support\Seed;
use Tests\Support\Accounts;

/**
 * The Inventory tables against PostgreSQL: dedup of blobs per team, the team trigger of applications, unique
 * subjects and images, the history purge, the collection of orphan blobs and their packages, and the team erasure.
 */
final class RepositoriesTest extends KernelTestCase
{
    private InventoryManager $manager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->manager = new InventoryManager(self::getContainer()->get('doctrine'));
    }

    #[Test]
    public function it_should_dedup_blobs_per_team_and_collect_the_orphans_with_their_packages(): void
    {
        $blobs = new DoctrineSnapshotBlobRepository($this->manager);
        $team = Uuid::fromString(Inv::TEAM);
        $old = SnapshotBlob::store(Uuid::v7(), $team, Inv::digest(1), MediaType::CycloneDx, 10, 'gz-1', new \DateTimeImmutable('-3 hours'));
        $kept = SnapshotBlob::store(Uuid::v7(), $team, Inv::digest(2), MediaType::CycloneDx, 10, 'gz-2', new \DateTimeImmutable('-3 hours'));
        $fresh = SnapshotBlob::store(Uuid::v7(), $team, Inv::digest(3), MediaType::CycloneDx, 10, 'gz-3', new \DateTimeImmutable());
        array_map($blobs->add(...), [$old, $kept, $fresh, SnapshotBlob::store(Uuid::v7(), Uuid::fromString(Inv::OTHER_TEAM), Inv::digest(1), MediaType::CycloneDx, 10, 'gz', new \DateTimeImmutable())]);
        new DoctrineSnapshotRecordRepository($this->manager)->add(SnapshotRecord::take(Uuid::v7(), $team, Uuid::fromString(Inv::RUN), SnapshotSubject::Image, Uuid::v7(), Inv::digest(2), new \DateTimeImmutable()));
        $this->flush();

        self::assertSame([Inv::digest(1)->value, Inv::digest(2)->value], $this->sorted($blobs->storedAmong($team, [Inv::digest(1)->value, Inv::digest(2)->value, Inv::digest(7)->value])));
        self::assertSame([], $blobs->storedAmong($team, []));
        self::assertSame('gz-2', $blobs->find($team, Inv::digest(2))?->gzip(), 'Read back from bytea.');
        self::assertNull($blobs->find($team, Inv::digest(7)));

        $packages = new DbalPackageRepository($this->connection());
        $packages->store($team, $old->id(), [new Package(Purl::parse('pkg:composer/a/b@1'), 'direct'), new Package(Purl::parse('pkg:npm/c@2'))]);
        self::assertTrue($packages->hasPackagesOf($old->id()));
        self::assertFalse($packages->hasPackagesOf($kept->id()));
        self::assertSame(['composer', 'a', 'b', '1', 'direct'], array_values((array) $this->connection()->fetchAssociative("SELECT type, namespace, name, version, dependency FROM inventory_package WHERE type = 'composer'")));

        self::assertSame(1, $blobs->deleteUnreferenced(10), 'The old orphan only: the referenced one stays, the fresh one may belong to an open inventory.');
        self::assertNull($blobs->find($team, Inv::digest(1)));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM inventory_package'), 'Its packages went with it.');
        $this->expectException(UniqueConstraintViolationException::class);
        $blobs->add(SnapshotBlob::store(Uuid::v7(), $team, Inv::digest(2), MediaType::CycloneDx, 10, 'again', new \DateTimeImmutable()));
        $this->flush();
    }

    #[Test]
    public function it_should_attach_an_application_to_an_environment_of_its_team_only(): void
    {
        [$teamId, $environmentId] = $this->environment();
        $apps = new DoctrineAppInstanceRepository($this->manager);
        $app = AppInstance::discover(Uuid::v7(), Uuid::fromString($teamId), Uuid::fromString(Inv::HOST), Inv::compose(), Inv::now());
        $app->assign(Uuid::fromString($environmentId));
        $apps->add($app);
        $this->flush();
        self::assertSame(1, $apps->countInEnvironment(Uuid::fromString($environmentId)));
        self::assertCount(1, $apps->ofHost(Uuid::fromString(Inv::HOST)));
        self::assertSame($environmentId, $apps->find($app->id())?->environmentId()?->toRfc4122());

        $foreign = AppInstance::discover(Uuid::v7(), Uuid::fromString(Inv::OTHER_TEAM), Uuid::v7(), Inv::compose(), Inv::now());
        $foreign->assign(Uuid::fromString($environmentId));
        $apps->add($foreign);
        try {
            $this->flush();
            self::fail('The trigger refuses an environment of another team.');
        } catch (DriverException $refused) {
            self::assertSame('23514', $refused->getSQLState());
        }
    }

    #[Test]
    public function it_should_keep_one_application_per_host_subject_and_one_image_per_team_image_id(): void
    {
        $images = new DoctrineContainerImageRepository($this->manager);
        $images->add(ContainerImage::discover(Uuid::v7(), Uuid::fromString(Inv::TEAM), Inv::image(), Inv::now()));
        $images->add(ContainerImage::discover(Uuid::v7(), Uuid::fromString(Inv::OTHER_TEAM), Inv::image(), Inv::now()));
        $this->flush();
        self::assertSame(Inv::TEAM, $images->findByImageId(Uuid::fromString(Inv::TEAM), Inv::IMAGE)?->teamId()->toRfc4122());
        self::assertNull($images->findByImageId(Uuid::fromString(Inv::TEAM), 'sha256:other'));
        self::assertNotNull($images->find(($images->findByImageId(Uuid::fromString(Inv::OTHER_TEAM), Inv::IMAGE) ?? self::fail('No image.'))->id()));

        $apps = new DoctrineAppInstanceRepository($this->manager);
        $apps->add(AppInstance::discover(Uuid::v7(), Uuid::fromString(Inv::TEAM), Uuid::fromString(Inv::HOST), Inv::path(), Inv::now()));
        $apps->add(AppInstance::discover(Uuid::v7(), Uuid::fromString(Inv::TEAM), Uuid::fromString(Inv::HOST), Inv::path(sbom: 5), Inv::now()));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->flush();
    }

    #[Test]
    public function it_should_purge_the_history_but_the_latest_entry_of_each_subject(): void
    {
        $records = new DoctrineSnapshotRecordRepository($this->manager);
        $team = Uuid::fromString(Inv::TEAM);
        $subject = Uuid::v7();
        foreach (['-100 days', '-95 days', '-1 day'] as $age) {
            $records->add(SnapshotRecord::take(Uuid::v7(), $team, Uuid::v7(), SnapshotSubject::Application, $subject, Inv::digest(1), new \DateTimeImmutable($age)));
        }
        $alone = Uuid::v7();
        $records->add(SnapshotRecord::take(Uuid::v7(), $team, Uuid::v7(), SnapshotSubject::Image, $alone, Inv::digest(2), new \DateTimeImmutable('-200 days')));
        $records->add(SnapshotRecord::take(Uuid::v7(), Uuid::fromString(Inv::OTHER_TEAM), Uuid::v7(), SnapshotSubject::Image, Uuid::v7(), Inv::digest(3), new \DateTimeImmutable('-200 days')));
        $this->flush();

        self::assertSame(2, $records->purgeBefore($team, new \DateTimeImmutable('-90 days')));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM inventory_snapshot WHERE subject_id = ?', [$subject->toRfc4122()]));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM inventory_snapshot WHERE subject_id = ?', [$alone->toRfc4122()]), 'The latest of a subject stays, however old.');
        self::assertSame(3, $this->rows('SELECT COUNT(*) FROM inventory_snapshot'));
    }

    #[Test]
    public function it_should_erase_the_inventory_of_a_deleted_team_only(): void
    {
        $team = Uuid::fromString(Inv::TEAM);
        $runs = new DoctrineInventoryRunRepository($this->manager);
        $runs->add(Inv::run());
        $runs->add(Inv::run(id: '019a6b31-5e00-7f12-a3b4-c5d6e7f80913', teamId: Inv::OTHER_TEAM));
        $hosts = new DoctrineHostInventoryRepository($this->manager);
        $host = HostInventory::of(Uuid::fromString(Inv::HOST), $team);
        $hosts->add($host);
        $images = new DoctrineContainerImageRepository($this->manager);
        $images->add(ContainerImage::discover(Uuid::v7(), $team, Inv::image(), Inv::now()));
        $apps = new DoctrineAppInstanceRepository($this->manager);
        $apps->add(AppInstance::discover(Uuid::v7(), $team, Uuid::fromString(Inv::HOST), Inv::path(), Inv::now()));
        $records = new DoctrineSnapshotRecordRepository($this->manager);
        $records->add(SnapshotRecord::take(Uuid::v7(), $team, Uuid::fromString(Inv::RUN), SnapshotSubject::HostFacts, Uuid::fromString(Inv::HOST), Inv::digest(9), Inv::now()));
        $blobs = new DoctrineSnapshotBlobRepository($this->manager);
        $blobs->add(SnapshotBlob::store(Uuid::v7(), $team, Inv::digest(9), MediaType::HostFacts, 1, 'gz', Inv::now()));
        $this->flush();
        self::assertNotNull($hosts->find(Uuid::fromString(Inv::HOST)));
        self::assertCount(1, $runs->openReceivedBefore(Inv::now()->modify('+1 second'), 1));
        self::assertCount(0, $runs->openReceivedBefore(Inv::now(), 10));

        new InventoryTeamContent($runs, $records, $apps, $images, $hosts, $blobs)->eraseTeam(Inv::TEAM);
        foreach (['inventory_run', 'inventory_host', 'inventory_container_image', 'inventory_app_instance', 'inventory_snapshot', 'inventory_blob'] as $table) {
            self::assertSame(0, $this->rows('SELECT COUNT(*) FROM '.$table.' WHERE team_id = ?', [Inv::TEAM]), $table);
        }
        self::assertNotNull($runs->find(Uuid::fromString('019a6b31-5e00-7f12-a3b4-c5d6e7f80913')), 'Another team keeps its inventory.');
    }

    /**
     * @return array{string, string} a team (Organization) and an environment of it
     */
    private function environment(): array
    {
        $alice = Accounts::create(self::getContainer(), 'alice@example.com');
        $team = Seed::team(self::getContainer(), 'Alpha', [[$alice, TeamRole::Admin]]);
        Seed::project(self::getContainer(), $team, 'Shop');
        $environmentId = Uuid::v7()->toRfc4122();
        $this->connection()->executeStatement('INSERT INTO organization_environment (id, project_id, name, production) SELECT ?, id, ?, true FROM organization_project', [$environmentId, 'production']);

        return [$team->id()->toString(), $environmentId];
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /**
     * @param list<string> $parameters
     */
    private function rows(string $sql, array $parameters = []): int
    {
        $count = $this->connection()->fetchOne($sql, $parameters);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function connection(): Connection
    {
        return $this->manager->get()->getConnection();
    }

    private function flush(): void
    {
        $this->manager->get()->flush();
        $this->manager->get()->clear();
    }
}
