<?php

declare(strict_types=1);

namespace Tests\Inventory\Integration;

use Doctrine\DBAL\Connection;
use Inventory\Application\Query\View\HistoryRow;
use Inventory\Domain\Model\AppInstance;
use Inventory\Domain\Model\ContainerImage;
use Inventory\Domain\Model\SnapshotRecord;
use Inventory\Domain\Model\SnapshotSubject;
use Inventory\Infrastructure\Doctrine\DbalInventoryReadModel;
use Inventory\Infrastructure\Doctrine\DoctrineAppInstanceRepository;
use Inventory\Infrastructure\Doctrine\DoctrineContainerImageRepository;
use Inventory\Infrastructure\Doctrine\DoctrineSnapshotRecordRepository;
use Inventory\Infrastructure\Doctrine\InventoryManager;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use Shared\Application\Query\PageRequest;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Tests\Inventory\Support\Inv;
use Tests\Organization\Support\Seed;
use Tests\Support\Accounts;

/**
 * The SQL of the read side the in-memory double cannot prove: the overview figures (present applications, in the
 * given environments) and the history (latest first, changed against the previous record of the same subject,
 * trigger of the inventory).
 */
final class ReadModelTest extends KernelTestCase
{
    private InventoryManager $manager;
    private DbalInventoryReadModel $read;

    /** @var array<string, string> run id => trigger */
    private array $runs = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->manager = new InventoryManager(self::getContainer()->get('doctrine'));
        $this->read = new DbalInventoryReadModel($this->connection());
        $this->runs = [];
    }

    #[Test]
    public function it_should_count_the_present_applications_and_the_images_of_a_team(): void
    {
        [$teamId, $environmentId] = $this->environment();
        $team = Uuid::fromString($teamId);
        $apps = new DoctrineAppInstanceRepository($this->manager);
        $attached = AppInstance::discover(Uuid::v7(), $team, Uuid::fromString(Inv::HOST), Uuid::fromString(Inv::AGENT), Inv::compose(), Inv::now());
        $attached->assign(Uuid::fromString($environmentId));
        $gone = AppInstance::discover(Uuid::v7(), $team, Uuid::fromString(Inv::HOST), Uuid::fromString(Inv::AGENT), Inv::path(), Inv::now());
        $gone->markGone();
        array_map($apps->add(...), [
            $attached, $gone,
            AppInstance::discover(Uuid::v7(), $team, Uuid::v7(), Uuid::fromString(Inv::AGENT), Inv::compose(), Inv::now()),
            AppInstance::discover(Uuid::v7(), Uuid::fromString(Inv::OTHER_TEAM), Uuid::v7(), Uuid::fromString(Inv::AGENT), Inv::compose(), Inv::now()),
        ]);
        $images = new DoctrineContainerImageRepository($this->manager);
        $images->add(ContainerImage::discover(Uuid::v7(), $team, Inv::image(), Inv::now()));
        $images->add(ContainerImage::discover(Uuid::v7(), Uuid::fromString(Inv::OTHER_TEAM), Inv::image(), Inv::now()));
        $this->manager->get()->flush();

        self::assertSame(['applications' => 2, 'unattached' => 1, 'images' => 1], $this->read->counts($teamId, null));
        self::assertSame(1, $this->read->counts($teamId, [$environmentId])['applications']);
        self::assertSame(0, $this->read->counts($teamId, [])['applications'], 'A guest of no environment.');
    }

    #[Test]
    public function it_should_list_the_history_latest_first_with_what_changed(): void
    {
        $team = Uuid::fromString(Inv::TEAM);
        $host = Uuid::fromString(Inv::HOST);
        $records = new DoctrineSnapshotRecordRepository($this->manager);
        $take = fn (string $trigger, SnapshotSubject $subject, Uuid $id, int $digest, string $at): SnapshotRecord => SnapshotRecord::take(Uuid::v7(), $team, $this->inventory($trigger), $subject, $id, Inv::digest($digest), new \DateTimeImmutable($at));
        foreach ([
            $take('scheduled', SnapshotSubject::HostFacts, $host, 1, '2026-11-01 08:00'),
            $take('scan_now', SnapshotSubject::HostFacts, $host, 1, '2026-11-02 08:00'),
            $take('scan_now', SnapshotSubject::HostPackages, $host, 3, '2026-11-02 08:00'),
            $take('startup', SnapshotSubject::HostFacts, $host, 2, '2026-11-03 08:00'),
            $take('scheduled', SnapshotSubject::Application, $host, 9, '2026-11-04 08:00'),
            $take('scheduled', SnapshotSubject::HostFacts, Uuid::v7(), 1, '2026-11-04 08:00'),
        ] as $record) {
            $records->add($record);
        }
        $this->manager->get()->flush();
        foreach ($this->runs as $id => $trigger) {
            $this->connection()->executeStatement("INSERT INTO inventory_run (id, agent_id, team_id, host_id, manifest, status, received_at) VALUES (?, ?, ?, ?, ?, 'processed', NOW())", [$id, Inv::AGENT, Inv::TEAM, Inv::HOST, json_encode(['trigger' => $trigger])]);
        }

        $page = $this->read->history([SnapshotSubject::HostFacts, SnapshotSubject::HostPackages], Inv::HOST, new PageRequest());
        self::assertSame(4, $page->total);
        self::assertSame([
            '2026-11-03 host_facts '.Inv::digest(2)->value.' changed startup',
            '2026-11-02 host_packages '.Inv::digest(3)->value.' changed scan_now',
            '2026-11-02 host_facts '.Inv::digest(1)->value.' unchanged scan_now',
            '2026-11-01 host_facts '.Inv::digest(1)->value.' changed scheduled',
        ], array_map(self::line(...), $page->items), 'Compared within one subject type; the first one kept counts as changed.');

        $second = $this->read->history([SnapshotSubject::HostFacts], Inv::HOST, new PageRequest(1, 1));
        self::assertSame([3, ['2026-11-02 host_facts '.Inv::digest(1)->value.' unchanged scan_now']], [$second->total, array_map(self::line(...), $second->items)]);

        $this->connection()->executeStatement('DELETE FROM inventory_run');
        self::assertNull($this->read->history([SnapshotSubject::Application], Inv::HOST, new PageRequest())->items[0]->trigger, 'Its inventory purged.');
    }

    private function inventory(string $trigger): Uuid
    {
        $id = Uuid::v7();
        $this->runs[$id->toRfc4122()] = $trigger;

        return $id;
    }

    private static function line(HistoryRow $row): string
    {
        return \sprintf('%s %s %s %s %s', $row->takenAt->format('Y-m-d'), $row->subject, $row->digest, $row->changed ? 'changed' : 'unchanged', $row->trigger);
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

    private function connection(): Connection
    {
        return $this->manager->get()->getConnection();
    }
}
