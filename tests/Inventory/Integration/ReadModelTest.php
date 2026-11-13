<?php

declare(strict_types=1);

namespace Tests\Inventory\Integration;

use Doctrine\DBAL\Connection;
use Inventory\Application\Query\View\HistoryRow;
use Inventory\Domain\Model\AppInstance;
use Inventory\Domain\Model\ContainerImage;
use Inventory\Domain\Model\DocumentationLink;
use Inventory\Domain\Model\HostLabel;
use Inventory\Domain\Model\SnapshotRecord;
use Inventory\Domain\Model\SnapshotSubject;
use Inventory\Infrastructure\Doctrine\DbalInventoryReadModel;
use Inventory\Infrastructure\Doctrine\DoctrineAppInstanceRepository;
use Inventory\Infrastructure\Doctrine\DoctrineContainerImageRepository;
use Inventory\Infrastructure\Doctrine\DoctrineHostLabelRepository;
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

    #[Test]
    public function it_should_find_the_key_packages_and_the_distro_of_a_snapshot(): void
    {
        $blob = function (string $team, string $digest, array $purls): void {
            /** @var list<array{string, string, ?string, string}> $purls */
            $id = Uuid::v7()->toRfc4122();
            $this->connection()->executeStatement("INSERT INTO inventory_blob (id, team_id, digest, media_type, size, content, stored_at) VALUES (?, ?, ?, 'application/json', 0, '', NOW())", [$id, $team, $digest]);
            foreach ($purls as [$purl, $type, $namespace, $name]) {
                $this->connection()->executeStatement('INSERT INTO inventory_package (id, team_id, blob_id, purl, type, namespace, name, version) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [Uuid::v7()->toRfc4122(), $team, $id, $purl, $type, $namespace, $name, '1']);
            }
        };
        $blob(Inv::TEAM, 'sha256:app', [
            ['pkg:composer/symfony/framework-bundle@1', 'composer', 'symfony', 'framework-bundle'],
            ['pkg:npm/%40angular/core@1', 'npm', '@angular', 'core'],
            ['pkg:npm/react@1', 'npm', null, 'react'],
            ['pkg:npm/vue@1', 'npm', null, 'vue'],
        ]);
        $blob(Inv::TEAM, 'sha256:image', [
            ['pkg:npm/zz@1?distro=nope', 'npm', null, 'zz'],
            ['pkg:deb/debian/b@1?arch=amd64', 'deb', 'debian', 'b'],
            ['pkg:deb/debian/c@1?arch=amd64&distro=debian-12', 'deb', 'debian', 'c'],
            ['pkg:deb/debian/d@1?distro=debian-13#x', 'deb', 'debian', 'd'],
        ]);
        $blob(Inv::OTHER_TEAM, 'sha256:app', [['pkg:npm/next@1', 'npm', null, 'next']]);
        $blob(Inv::TEAM, 'sha256:rpm', [['pkg:rpm/rocky/e@1?distro=rocky%2D9', 'rpm', 'rocky', 'e']]);

        $named = $this->read->packagesNamed(Inv::TEAM, 'sha256:app', ['composer:symfony/framework-bundle', 'npm:@angular/core', 'npm:react', 'npm:next']);
        self::assertSame(['pkg:composer/symfony/framework-bundle@1', 'pkg:npm/%40angular/core@1', 'pkg:npm/react@1'], array_map(static fn ($p): string => $p->purl, $named), 'Of this team and snapshot, the names asked only.');
        self::assertSame(['composer', 'symfony', 'framework-bundle', '1'], [$named[0]->type, $named[0]->namespace, $named[0]->name, $named[0]->version]);
        self::assertSame([], $this->read->packagesNamed(Inv::TEAM, 'sha256:app', []));
        self::assertSame('debian-12', $this->read->distro(Inv::TEAM, 'sha256:image'), 'OS packages only, the first with a distro.');
        self::assertSame('rocky-9', $this->read->distro(Inv::TEAM, 'sha256:rpm'));
        self::assertNull($this->read->distro(Inv::TEAM, 'sha256:app'));
        self::assertNull($this->read->distro(Inv::OTHER_TEAM, 'sha256:image'));
    }

    #[Test]
    public function it_should_show_a_host_under_its_label_and_an_application_with_its_links(): void
    {
        $team = Uuid::fromString(Inv::TEAM);
        $apps = new DoctrineAppInstanceRepository($this->manager);
        $app = AppInstance::discover(Uuid::v7(), $team, Uuid::fromString(Inv::HOST), Uuid::fromString(Inv::AGENT), Inv::compose(), Inv::now());
        $app->document([new DocumentationLink('https://wiki.example.com/shop', 'Runbook')]);
        $apps->add($app);
        $this->connection()->executeStatement("INSERT INTO inventory_host (host_id, team_id, facts) VALUES (?, ?, '{\"hostname\": \"web-01\"}')", [Inv::HOST, Inv::TEAM]);
        $this->manager->get()->flush();

        $row = $this->read->application($app->id()->toRfc4122());
        self::assertSame(['web-01', [['url' => 'https://wiki.example.com/shop', 'label' => 'Runbook']]], [$row?->hostname, $row?->links]);
        self::assertNull($this->read->hostLabel(Inv::HOST));
        self::assertSame([null, []], [$this->read->host(Inv::HOST)?->displayName, $this->read->host(Inv::HOST)?->addresses]);

        $labels = new DoctrineHostLabelRepository($this->manager);
        $labels->add(HostLabel::of(Uuid::fromString(Inv::HOST), $team, 'Shop front', ['10.0.0.1']));
        $this->manager->get()->flush();
        self::assertSame('Shop front', $this->read->application($app->id()->toRfc4122())?->hostname);
        self::assertSame(['Shop front', 'web-01', ['10.0.0.1']], [$this->read->host(Inv::HOST)?->displayName, $this->read->host(Inv::HOST)?->hostname, $this->read->host(Inv::HOST)?->addresses]);
        self::assertSame(['displayName' => 'Shop front', 'addresses' => ['10.0.0.1']], $this->read->hostLabel(Inv::HOST));
        self::assertSame(['Shop front'], array_map(static fn ($r): string => $r->hostname, $this->read->applications(Inv::TEAM, null, false, null, null, new PageRequest(search: 'shop front'))->items), 'Searched by its display name.');
        $hosts = array_values(array_filter($this->read->teamSubjects(Inv::TEAM), static fn ($s): bool => 'host' === $s->type));
        self::assertSame(['Shop front'], array_map(static fn ($s): string => $s->name, $hosts));
    }

    #[Test]
    public function it_should_read_the_components_of_an_environment(): void
    {
        [$teamId, $environmentId] = $this->environment();
        $team = Uuid::fromString($teamId);
        $apps = new DoctrineAppInstanceRepository($this->manager);
        $container = AppInstance::discover(Uuid::v7(), $team, Uuid::fromString(Inv::HOST), Uuid::fromString(Inv::AGENT), Inv::compose(sbom: 1), Inv::now());
        $path = AppInstance::discover(Uuid::v7(), $team, Uuid::fromString(Inv::HOST), Uuid::fromString(Inv::AGENT), Inv::path(sbom: 3), Inv::now());
        $elsewhere = AppInstance::discover(Uuid::v7(), $team, Uuid::fromString(Inv::HOST), Uuid::fromString(Inv::AGENT), Inv::path('/srv/other', 3), Inv::now());
        $container->assign(Uuid::fromString($environmentId));
        $path->assign(Uuid::fromString($environmentId));
        array_map($apps->add(...), [$container, $path, $elsewhere]);
        $images = new DoctrineContainerImageRepository($this->manager);
        $images->add(ContainerImage::discover(Uuid::v7(), $team, Inv::image(2), Inv::now()));
        $this->connection()->executeStatement('INSERT INTO inventory_host (host_id, team_id, facts) VALUES (?, ?, ?)', [Inv::HOST, $teamId, '{"hostname": "web-01", "os": {"id": "ubuntu", "version_id": "24.04"}}']);
        $this->manager->get()->flush();
        $blob = function (int $digest, array $packages) use ($teamId): void {
            /** @var list<array{string, string, ?string, string, ?string, ?string}> $packages */
            $id = Uuid::v7()->toRfc4122();
            $this->connection()->executeStatement("INSERT INTO inventory_blob (id, team_id, digest, media_type, size, content, stored_at) VALUES (?, ?, ?, 'application/json', 0, '', NOW())", [$id, $teamId, Inv::digest($digest)->value]);
            foreach ($packages as [$purl, $type, $namespace, $name, $version, $dependency]) {
                $this->connection()->executeStatement('INSERT INTO inventory_package (id, team_id, blob_id, purl, type, namespace, name, version, dependency) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [Uuid::v7()->toRfc4122(), $teamId, $id, $purl, $type, $namespace, $name, $version, $dependency]);
            }
        };
        $blob(1, [['pkg:composer/symfony/http-kernel@6.4.3', 'composer', 'symfony', 'http-kernel', '6.4.3', 'direct'], ['pkg:composer/psr/log@3.0.0', 'composer', 'psr', 'log', '3.0.0', 'transitive'], ['pkg:npm/react', 'npm', null, 'react', null, 'direct']]);
        $blob(2, [['pkg:apk/alpine/musl@1.2.5?arch=x86_64&distro=alpine-3.21', 'apk', 'alpine', 'musl', '1.2.5', null]]);
        $blob(3, [['pkg:npm/lodash@4.17.21', 'npm', null, 'lodash', '4.17.21', 'direct']]);

        $entries = array_map(static fn ($e): string => \sprintf('%s %s %s %s %s %s', $e->kind, $e->ecosystem, $e->component, $e->version, $e->application, $e->host), $this->read->matrixEntries($teamId, $environmentId));
        sort($entries);
        self::assertSame([
            'os alpine Alpine 3.21 shop / php web-01',
            'os ubuntu Ubuntu 24.04 /opt/legacy-app web-01',
            'package composer symfony/http-kernel 6.4.3 shop / php web-01',
            'package npm lodash 4.17.21 /opt/legacy-app web-01',
            'runtime php php 8.2.26 /opt/legacy-app web-01',
        ], $entries, 'Direct dependencies with a version; the OS of the image, else of the host; nothing of another environment.');
        self::assertSame([], $this->read->matrixEntries($teamId, Uuid::v7()->toRfc4122()));
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
