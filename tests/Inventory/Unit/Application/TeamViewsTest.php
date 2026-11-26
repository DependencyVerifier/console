<?php

declare(strict_types=1);

namespace Tests\Inventory\Unit\Application;

use Inventory\Application\Command\Application\AttachApplication;
use Inventory\Application\Command\Application\DetachApplication;
use Inventory\Application\Command\Application\DocumentApplication;
use Inventory\Application\Command\Application\Handler\ApplicationAttachment;
use Inventory\Application\Command\Application\Handler\AttachApplicationHandler;
use Inventory\Application\Command\Application\Handler\DetachApplicationHandler;
use Inventory\Application\Command\Application\Handler\DocumentApplicationHandler;
use Inventory\Application\Command\Host\Handler\RecordHostLabelHandler;
use Inventory\Application\Command\Host\RecordHostLabel;
use Inventory\Application\EventHandler\CopyHostLabel;
use Inventory\Application\Query\OsName;
use Inventory\Application\Query\Team\CountInventory;
use Inventory\Application\Query\Team\GetApplication;
use Inventory\Application\Query\Team\GetEnvironmentMatrix;
use Inventory\Application\Query\Team\GetHostInventory;
use Inventory\Application\Query\Team\GetImage;
use Inventory\Application\Query\Team\Handler\CountInventoryHandler;
use Inventory\Application\Query\Team\Handler\GetApplicationHandler;
use Inventory\Application\Query\Team\Handler\GetEnvironmentMatrixHandler;
use Inventory\Application\Query\Team\Handler\GetHostInventoryHandler;
use Inventory\Application\Query\Team\Handler\GetImageHandler;
use Inventory\Application\Query\Team\Handler\InventoryVisibility;
use Inventory\Application\Query\Team\Handler\ListApplicationsHandler;
use Inventory\Application\Query\Team\Handler\ListInventoryHistoryHandler;
use Inventory\Application\Query\Team\Handler\ListPackagesHandler;
use Inventory\Application\Query\Team\ListApplications;
use Inventory\Application\Query\Team\ListInventoryHistory;
use Inventory\Application\Query\Team\ListPackages;
use Inventory\Application\Query\View\ApplicationView;
use Inventory\Application\Query\View\AppRow;
use Inventory\Application\Query\View\HistoryRow;
use Inventory\Application\Query\View\HostView;
use Inventory\Application\Query\View\ImageView;
use Inventory\Application\Query\View\InventoryCounts;
use Inventory\Application\Query\View\KeyPackage;
use Inventory\Application\Query\View\MatrixEntry;
use Inventory\Application\Query\View\MatrixRow;
use Inventory\Application\Query\View\PackageRow;
use Inventory\Application\Security\InventoryAccess;
use Inventory\Application\Security\InventoryScope;
use Inventory\Domain\Event\ApplicationPlaced;
use Inventory\Domain\Model\AppInstance;
use Inventory\Domain\Model\Assignment;
use Inventory\Domain\Model\DocumentationLink;
use Inventory\Domain\Model\EnvironmentOfAnotherTeam;
use Inventory\Domain\Model\InvalidDocumentationLink;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Audit\Target;
use Shared\Application\Authorization\PermissionChecker;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Contract\Fleet\FindTeamHost;
use Shared\Application\Contract\Fleet\HostLabelled;
use Shared\Application\Contract\Organization\GetVisibleProjects;
use Shared\Application\Contract\Organization\ListTeamEnvironments;
use Shared\Application\Contract\Organization\TeamEnvironment;
use Shared\Application\Contract\Organization\VisibleProjects;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;
use Tests\Inventory\Support\InMemoryApps;
use Tests\Inventory\Support\InMemoryHostLabels;
use Tests\Inventory\Support\InMemoryReadModel;
use Tests\Inventory\Support\Inv;
use Tests\Organization\Support\FixedQueries;
use Tests\Organization\Support\RecordingCommands;

/**
 * What the team pages show and who attaches: a member sees the whole inventory of its team, a TEAM_GUEST the
 * applications of its projects and the hosts and images they use; project.apps.assign is checked on the target and on
 * the current project.
 */
final class TeamViewsTest extends TestCase
{
    private const string SHOP = '019a6b2c-3f00-7a11-8b22-3c44d55e6f01';
    private const string BLOG = '019a6b2c-3f00-7a11-8b22-3c44d55e6f02';
    private const string BLOG_ENV = '019a6b2c-4f00-7a11-8b22-3c44d55e6f02';
    private const string BLOG_APP = '019a6b31-6e00-7f12-a3b4-c5d6e7f80902';
    private const string LOOSE_APP = '019a6b31-6e00-7f12-a3b4-c5d6e7f80903';
    private const string HOST2 = '019a6b2c-7f00-7a11-8b22-3c44d55e6f02';
    private const string IMAGE_RECORD = '019a6b31-7e00-7f12-a3b4-c5d6e7f80901';

    private InMemoryReadModel $read;
    private FixedQueries $queries;

    /** @var list<string> "projectId" where project.apps.assign is granted */
    private array $assignIn = [self::SHOP, self::BLOG];

    private bool $guest = false;

    /** Whether the actor holds host.edit on the team. */
    public bool $editsHosts = false;

    protected function setUp(): void
    {
        $this->read = new InMemoryReadModel();
        $this->read->apps = [
            self::row(Inv::APP, Inv::ENVIRONMENT, Inv::HOST, Inv::IMAGE),
            self::row(self::BLOG_APP, self::BLOG_ENV, self::HOST2),
            self::row(self::LOOSE_APP, null, Inv::HOST),
        ];
        $this->read->hosts = [new HostView(Inv::HOST, Inv::TEAM, 'web-01', [], 'sha256:os', null, 3), new HostView(self::HOST2, Inv::TEAM, 'web-02', [], null, null, 0)];
        $this->read->images = [new ImageView(self::IMAGE_RECORD, Inv::TEAM, Inv::IMAGE, ['shop:1'], [], 'registry', null, Inv::now(), 'sha256:image', 1)];
        $this->read->packages = [
            Inv::TEAM.' sha256:app' => [new PackageRow('pkg:composer/symfony/http-kernel@6.4.3', 'composer', 'symfony', 'http-kernel', '6.4.3', 'direct', 'composer')],
            Inv::TEAM.' sha256:image' => [new PackageRow('pkg:deb/debian/openssl@3', 'deb', 'debian', 'openssl', '3', null, null), new PackageRow('pkg:deb/debian/zlib@1', 'deb', 'debian', 'zlib', '1', null, null)],
            Inv::TEAM.' sha256:os' => [],
        ];
        $this->queries = new FixedQueries([
            GetVisibleProjects::class => fn (object $q): VisibleProjects => $q instanceof GetVisibleProjects && Inv::TEAM === $q->teamId
                ? ($this->guest ? new VisibleProjects(false, [self::SHOP]) : new VisibleProjects(true)) : throw ResourceNotFound::of('team'),
            ListTeamEnvironments::class => static fn (object $q): array => [
                new TeamEnvironment(self::BLOG_ENV, 'production', self::BLOG, 'Blog'),
                new TeamEnvironment(Inv::ENVIRONMENT, 'production', self::SHOP, 'Shop'),
            ],
            FindTeamHost::class => static fn (object $q): ?string => $q instanceof FindTeamHost && Inv::TEAM === $q->teamId && '019a6b2c-7f00-7a11-8b22-3c44d55e6f03' === $q->hostId ? 'web-03' : null,
        ]);
    }

    #[Test]
    public function it_should_name_an_application_and_read_the_facts_of_a_host(): void
    {
        $row = self::named(...);
        self::assertSame('Shop', $row(['labels' => ['name' => 'Shop'], 'compose_project' => 'shop', 'service' => 'php'])->name());
        self::assertSame('shop / php', $row(['labels' => ['name' => ''], 'compose_project' => 'shop', 'service' => 'php'])->name());
        self::assertSame('/srv/app', $row(['labels' => 'x', 'service' => 'php', 'path' => '/srv/app'])->name(), 'A service without its project is no name.');
        self::assertSame('/srv/app', $row(['compose_project' => 'shop', 'path' => '/srv/app'])->name());
        self::assertSame('/srv/app', $row(['compose_project' => 7, 'service' => 'php', 'path' => '/srv/app'])->name());
        self::assertSame('/srv/app', $row(['compose_project' => 'shop', 'service' => 7, 'path' => '/srv/app'])->name());
        self::assertSame('worker', $row(['path' => 7, 'container_name' => 'worker'])->name());
        self::assertSame('compose:shop/php', $row([])->name());
        self::assertSame('php 8.4.1', $row(['runtime' => ['name' => 'php', 'version' => '8.4.1']])->runtime());
        self::assertNull($row(['runtime' => ['name' => 'php']])->runtime());
        self::assertNull($row(['runtime' => ['version' => '8.4.1']])->runtime());
        self::assertNull($row(['runtime' => 'php'])->runtime());
        self::assertSame(['php' => '^8.2', 'node' => '>=20'], $row(['requires' => ['node' => '>=20', 'ruby' => '3', 'php' => '^8.2']])->requires());
        self::assertSame(['node' => '>=20'], $row(['requires' => ['php' => 8, 'node' => '>=20']])->requires());
        self::assertSame([], $row(['requires' => 'php'])->requires());
        self::assertSame([], $row([])->requires());
        $named = $row(['path' => '/srv/app'])->withEnvironment('Shop / production');
        self::assertSame(['Shop / production', '/srv/app', Inv::APP, 2, 'sha256:a', 'none', true, 'web-01'], [$named->environment, $named->name(), $named->id, $named->packages, $named->sbomDigest, $named->assignment, $named->present, $named->hostname]);
        self::assertSame([Inv::TEAM, Inv::HOST, 'compose:shop/php', 'compose_service', null, null, '2026-11-09 08:02'], [$named->teamId, $named->hostId, $named->subjectKey, $named->kind, $named->imageId, $named->environmentId, $named->lastSeenAt->format('Y-m-d H:i')]);

        $host = new HostView(Inv::HOST, Inv::TEAM, 'web-01', [
            'os' => ['pretty_name' => 'Debian GNU/Linux 12'], 'kernel' => '6.1.0', 'arch' => 7,
            'runtimes' => [['name' => 'php', 'version' => '8.4.1', 'service' => 'php'], ['name' => 'node', 'version' => '22.11.0', 'manager' => 'nvm'], ['name' => 'go'], ['version' => '1'], ['name' => 'ruby', 'version' => '3.3.14', 'service' => 3, 'manager' => 4], 'x'],
            'docker' => ['engine_version' => '27.3.1'],
        ], null, null, 0);
        self::assertSame(['Debian GNU/Linux 12', '6.1.0', null, null, '27.3.1'], [$host->os(), $host->fact('kernel'), $host->fact('arch'), $host->fact('missing'), $host->dockerVersion()]);
        self::assertSame([
            ['name' => 'php', 'version' => '8.4.1', 'service' => 'php', 'manager' => null],
            ['name' => 'node', 'version' => '22.11.0', 'service' => null, 'manager' => 'nvm'],
            ['name' => 'ruby', 'version' => '3.3.14', 'service' => null, 'manager' => null],
        ], $host->runtimes());
        $bare = new HostView(Inv::HOST, Inv::TEAM, 'web-01', ['os' => 'debian', 'runtimes' => 'php', 'docker' => '27'], null, null, 0);
        self::assertSame([null, [], null], [$bare->os(), $bare->runtimes(), $bare->dockerVersion()]);
        $empty = new HostView(Inv::HOST, Inv::TEAM, 'web-01', ['os' => ['pretty_name' => 12], 'docker' => ['engine_version' => 27]], null, null, 0);
        self::assertSame([null, null], [$empty->os(), $empty->dockerVersion()]);

        self::assertSame('symfony/http-kernel', new PackageRow('p', 'composer', 'symfony', 'http-kernel', null, null, null)->fullName());
        self::assertSame('lodash', new PackageRow('p', 'npm', null, 'lodash', null, null, null)->fullName());
    }

    #[Test]
    public function it_should_scope_the_inventory_of_a_member_and_of_a_guest(): void
    {
        $member = $this->access()->scope(Inv::TEAM);
        self::assertTrue($member->seesAll());
        self::assertTrue($member->seesEnvironment(null));
        self::assertTrue($member->seesProject(self::BLOG));
        self::assertSame([self::BLOG_ENV, Inv::ENVIRONMENT], array_keys($member->environments));
        self::assertSame('Shop / production', $member->environmentName(Inv::ENVIRONMENT));
        self::assertSame('', $member->environmentName(null));
        self::assertSame('', $member->environmentName('019a6b2c-4f00-7a11-8b22-3c44d55e6f09'));
        self::assertSame([Inv::ENVIRONMENT], $member->environmentsOf(self::SHOP));
        self::assertSame([], $member->environmentsOf('019a6b2c-3f00-7a11-8b22-3c44d55e6f09'));

        $this->guest = true;
        $guest = $this->access()->scope(Inv::TEAM);
        self::assertFalse($guest->seesAll());
        self::assertSame([Inv::ENVIRONMENT], $guest->visibleEnvironments);
        self::assertSame([self::SHOP], $guest->visibleProjects);
        self::assertTrue($guest->seesEnvironment(Inv::ENVIRONMENT));
        self::assertFalse($guest->seesEnvironment(self::BLOG_ENV));
        self::assertFalse($guest->seesEnvironment(null), 'Not attached: in no project.');
        self::assertFalse($guest->seesProject(self::BLOG));
        self::assertFalse(new InventoryScope(Inv::TEAM, [], [])->seesEnvironment(null));

        $this->expectException(ResourceNotFound::class);
        $this->access()->scope(Inv::OTHER_TEAM);
    }

    #[Test]
    public function it_should_list_the_applications_the_actor_sees(): void
    {
        $list = fn (?string $project = null, bool $unattached = false, ?string $host = null, ?string $image = null): array => array_map(
            static fn (AppRow $r): string => $r->id.' '.$r->environment,
            (new ListApplicationsHandler($this->access(), $this->read))(new ListApplications(Inv::TEAM, new PageRequest(), $project, $unattached, $host, $image))->items,
        );

        self::assertSame([Inv::APP.' Shop / production', self::BLOG_APP.' Blog / production', self::LOOSE_APP.' '], $list());
        self::assertFalse(new ListApplications(Inv::TEAM)->unattachedOnly, 'Every application by default.');
        self::assertSame([self::LOOSE_APP.' '], $list(unattached: true));
        self::assertSame([Inv::APP.' Shop / production'], $list(self::SHOP));
        self::assertSame([Inv::APP.' Shop / production', self::LOOSE_APP.' '], $list(host: Inv::HOST));
        self::assertSame([Inv::APP.' Shop / production'], $list(image: Inv::IMAGE));
        self::assertSame(['environments' => null, 'unattached' => false, 'host' => null, 'image' => Inv::IMAGE], $this->read->lastFilter);
        $byEnvironment = fn (string $environment): array => array_map(static fn (AppRow $r): string => $r->id, (new ListApplicationsHandler($this->access(), $this->read))(new ListApplications(Inv::TEAM, environmentId: $environment))->items);
        self::assertSame([self::BLOG_APP], $byEnvironment(self::BLOG_ENV));
        self::assertSame(['environments' => [self::BLOG_ENV], 'unattached' => false, 'host' => null, 'image' => null], $this->read->lastFilter);
        $this->assertNotFound(static fn () => $byEnvironment('019a6b2c-4f00-7a11-8b22-3c44d55e6f09'), 'Not an environment of the team.');
        self::assertNull(new ListApplications(Inv::TEAM)->environmentId);
        $attached = fn (?string $project = null): array => array_map(static fn (AppRow $r): string => $r->id, (new ListApplicationsHandler($this->access(), $this->read))(new ListApplications(Inv::TEAM, projectId: $project, attachedOnly: true))->items);
        self::assertSame([Inv::APP, self::BLOG_APP], $attached(), 'Attached only: in an environment of the team.');
        self::assertSame(['environments' => [self::BLOG_ENV, Inv::ENVIRONMENT], 'unattached' => false, 'host' => null, 'image' => null], $this->read->lastFilter);
        self::assertSame([Inv::APP], $attached(self::SHOP), 'Within a project, its environments.');
        self::assertFalse(new ListApplications(Inv::TEAM)->attachedOnly);

        $this->guest = true;
        self::assertSame([Inv::APP], $attached(), 'A guest: its environments only.');
        self::assertSame([Inv::APP.' Shop / production'], $list());
        self::assertSame([Inv::APP.' Shop / production'], $list(self::SHOP));
        $this->read->lastFilter = [];
        $none = (new ListApplicationsHandler($this->access(), $this->read))(new ListApplications(Inv::TEAM, unattachedOnly: true));
        self::assertSame([[], 0, 0], [$none->items, $none->filtered, $none->total]);
        self::assertSame([], $this->read->lastFilter, 'Never asked.');
        $this->assertNotFound(static fn () => $byEnvironment(self::BLOG_ENV), 'An environment of a project it does not see.');
        $this->expectException(ResourceNotFound::class);
        $list(self::BLOG);
    }

    #[Test]
    public function it_should_show_an_application_with_the_gestures_of_the_actor(): void
    {
        $get = fn (string $id) => (new GetApplicationHandler($this->access(), $this->read))(new GetApplication($id));

        $view = $get(Inv::APP);
        self::assertSame(['Shop / production', self::IMAGE_RECORD, true], [$view->app->environment, $view->imageRecordId, $view->mayDetach]);
        self::assertSame([self::BLOG_ENV, Inv::ENVIRONMENT], array_map(static fn (TeamEnvironment $e): string => $e->environmentId, $view->assignable));

        $this->assignIn = [self::BLOG];
        $view = $get(Inv::APP);
        self::assertFalse($view->mayDetach, 'No permission on its project.');
        self::assertSame([self::BLOG_ENV], array_map(static fn (TeamEnvironment $e): string => $e->environmentId, $view->assignable));
        $loose = $get(self::LOOSE_APP);
        self::assertSame([false, null, ''], [$loose->mayDetach, $loose->imageRecordId, $loose->app->environment]);

        $this->assignIn = [self::SHOP, self::BLOG];
        $this->guest = true;
        self::assertSame([Inv::ENVIRONMENT], array_map(static fn (TeamEnvironment $e): string => $e->environmentId, $get(Inv::APP)->assignable), 'Only into the projects it sees.');
        foreach ([self::BLOG_APP, self::LOOSE_APP, 'not-a-uuid', '019a6b31-6e00-7f12-a3b4-c5d6e7f80909'] as $hidden) {
            try {
                $get($hidden);
                self::fail($hidden);
            } catch (ResourceNotFound) {
            }
        }
    }

    #[Test]
    public function it_should_tell_the_frameworks_and_the_os_of_an_application(): void
    {
        $get = fn (string $id): ApplicationView => (new GetApplicationHandler($this->access(), $this->read))(new GetApplication($id));
        $this->read->packages[Inv::TEAM.' sha256:app'] = [
            new PackageRow('pkg:npm/react@19.0.0', 'npm', null, 'react', '19.0.0', 'direct', 'npm'),
            new PackageRow('pkg:composer/symfony/http-kernel@7.3.1', 'composer', 'symfony', 'http-kernel', '7.3.1', 'direct', 'composer'),
            new PackageRow('pkg:composer/symfony/framework-bundle@7.3.1', 'composer', 'symfony', 'framework-bundle', '7.3.1', 'direct', 'composer'),
            new PackageRow('pkg:npm/%40angular/core', 'npm', '@angular', 'core', null, null, 'npm'),
            new PackageRow('pkg:npm/react@18.0.0', 'npm', null, 'react', '18.0.0', null, 'npm'),
        ];
        $this->read->packages[Inv::TEAM.' sha256:image'] = [new PackageRow('pkg:deb/debian/openssl@3?arch=amd64&distro=debian-12', 'deb', 'debian', 'openssl', '3', null, null)];

        $view = $get(Inv::APP);
        self::assertEquals([new KeyPackage('Symfony', '7.3.1'), new KeyPackage('Angular', null), new KeyPackage('React', '19.0.0')], $view->frameworks, 'In the order of the list, the first version found.');
        self::assertSame('Debian 12', $view->os, 'The OS of its image.');

        $this->read->packages[Inv::TEAM.' sha256:image'] = [];
        $this->read->hosts[0] = new HostView(Inv::HOST, Inv::TEAM, 'web-01', ['os' => ['pretty_name' => 'Ubuntu 24.04 LTS']], 'sha256:os', null, 3);
        self::assertNull($get(Inv::APP)->os, 'An image without OS packages: no OS, never that of its host.');
        self::assertSame('Ubuntu 24.04 LTS', $get(self::LOOSE_APP)->os, 'No image: the OS of its host.');
        $this->read->packages[Inv::TEAM.' sha256:app'] = [];
        self::assertSame([], $get(self::LOOSE_APP)->frameworks);
    }

    #[Test]
    public function it_should_lay_out_the_components_of_an_environment(): void
    {
        $entry = static fn (string $kind, string $ecosystem, string $component, string $version, string $app, string $host = 'web-01'): MatrixEntry => new MatrixEntry($kind, $ecosystem, $component, $version, $app.'-id', $app, $host.'-id', $host);
        $this->read->matrix = [
            $entry('package', 'composer', 'symfony/http-kernel', '6.4.10', 'front'),
            $entry('package', 'composer', 'symfony/http-kernel', '6.4.3', 'api', 'web-02'),
            $entry('package', 'composer', 'symfony/http-kernel', '6.4.3', 'worker', 'web-03'),
            $entry('runtime', 'php', 'php', '8.3.14', 'front'),
            $entry('os', 'debian', 'Debian', '12', 'front'),
            $entry('package', 'npm', 'React', '19.0.0', 'front'),
            $entry('package', 'composer', 'Monolog/monolog', '3.0.0', 'front'),
            $entry('package', 'npm', 'symfony/http-kernel', '1.0.0', 'front'),
        ];
        $matrix = fn (bool $drift = false, string $search = '', int $offset = 0, int $limit = 25, string $environment = Inv::ENVIRONMENT) => (new GetEnvironmentMatrixHandler($this->access(), $this->read))(new GetEnvironmentMatrix(Inv::TEAM, $environment, new PageRequest($offset, $limit, search: $search), $drift));
        $lines = self::matrixLines(...);

        $all = $matrix();
        self::assertSame([
            'runtime php php 8.3.14 1 front@web-01',
            'os debian Debian 12 1 front@web-01',
            'package composer Monolog/monolog 3.0.0 1 front@web-01',
            'package npm React 19.0.0 1 front@web-01',
            'package composer symfony/http-kernel 6.4.3 2 api@web-02,worker@web-03',
            'package composer symfony/http-kernel 6.4.10 2 front@web-01',
            'package npm symfony/http-kernel 1.0.0 1 front@web-01',
        ], $lines($all), 'Runtimes, OS, then packages by name whatever the case, then ecosystem; versions in their order.');
        self::assertSame([7, 7], [$all->filtered, $all->total]);
        self::assertSame(['matrix' => [Inv::TEAM, Inv::ENVIRONMENT]], $this->read->lastFilter);
        self::assertSame([Inv::TEAM, true, false], [$all->items[4]->teamId, $all->items[4]->drifts(), $all->items[0]->drifts()]);
        self::assertSame(['package composer symfony/http-kernel 6.4.3 2 api@web-02,worker@web-03', 'package composer symfony/http-kernel 6.4.10 2 front@web-01'], $lines($drifts = $matrix(true)), 'The drifts only.');
        self::assertSame([2, 7], [$drifts->filtered, $drifts->total]);
        self::assertSame(['package composer Monolog/monolog 3.0.0 1 front@web-01'], $lines($matrix(search: ' MONOLOG ')));
        self::assertSame(['package npm React 19.0.0 1 front@web-01', 'package composer symfony/http-kernel 6.4.3 2 api@web-02,worker@web-03'], $lines($matrix(offset: 3, limit: 2)));
        self::assertFalse(new GetEnvironmentMatrix(Inv::TEAM, Inv::ENVIRONMENT)->driftOnly);
        $this->assertNotFound(static fn () => $matrix(environment: '019a6b2c-4f00-7a11-8b22-3c44d55e6f09'), 'Not an environment of the team.');
        $this->guest = true;
        $this->assertNotFound(static fn () => $matrix(environment: self::BLOG_ENV), 'An environment of a project it does not see.');
        self::assertCount(7, $matrix()->items);
    }

    #[Test]
    public function it_should_name_the_os_of_a_distro(): void
    {
        foreach ([
            'alpine-3.21' => 'Alpine 3.21', 'debian-12' => 'Debian 12', 'ubuntu-24.04' => 'Ubuntu 24.04', 'rhel-9.4' => 'RHEL 9.4', 'centos-7' => 'CentOS 7',
            'rocky-9.5' => 'Rocky Linux 9.5', 'almalinux-9' => 'AlmaLinux 9', 'amzn-2023' => 'Amazon Linux 2023', 'fedora-41' => 'Fedora 41', 'ol-9' => 'Oracle Linux 9',
            'opensuse-leap-15.6' => 'openSUSE Leap 15.6', 'sles-15.6' => 'SUSE Linux Enterprise 15.6', 'wolfi-20230201' => 'Wolfi 20230201',
            'gentoo-2.17' => 'Gentoo 2.17', 'arch' => 'Arch', 'debian-' => 'Debian-', 'debian-x1' => 'Debian-x1', 'debian-12-slim' => 'Debian-12-slim',
        ] as $distro => $name) {
            self::assertSame($name, OsName::of($distro), $distro);
        }
    }

    #[Test]
    public function it_should_show_a_host_or_an_image_to_who_sees_one_of_its_applications(): void
    {
        $host = fn (string $id, string $team = Inv::TEAM): HostView => (new GetHostInventoryHandler($this->visibility()))(new GetHostInventory($team, $id));
        $image = fn (string $id, string $team = Inv::TEAM): ImageView => (new GetImageHandler($this->visibility()))(new GetImage($team, $id));

        self::assertSame('web-01', $host(Inv::HOST)->hostname);
        self::assertSame('web-02', $host(self::HOST2)->hostname);
        $fresh = $host('019a6b2c-7f00-7a11-8b22-3c44d55e6f03');
        self::assertSame(['019a6b2c-7f00-7a11-8b22-3c44d55e6f03', Inv::TEAM, 'web-03', [], null, null, 0], [$fresh->hostId, $fresh->teamId, $fresh->hostname, $fresh->facts, $fresh->packagesDigest, $fresh->inventoriedAt, $fresh->packages], 'Enrolled, not inventoried yet.');
        self::assertSame(Inv::IMAGE, $image(self::IMAGE_RECORD)->imageId);
        $this->assertNotFound(static fn () => $host('019a6b2c-7f00-7a11-8b22-3c44d55e6f09'));
        $this->assertNotFound(static fn () => $host('nope'));
        $this->assertNotFound(static fn () => $image('nope'));
        $this->assertNotFound(static fn () => $image('019a6b31-7e00-7f12-a3b4-c5d6e7f80909'));
        $this->read->hosts[] = new HostView('019a6b2c-7f00-7a11-8b22-3c44d55e6f04', Inv::OTHER_TEAM, 'other', [], null, null, 0);
        $this->read->images[] = new ImageView('019a6b31-7e00-7f12-a3b4-c5d6e7f80904', Inv::OTHER_TEAM, Inv::IMAGE, [], [], null, null, Inv::now(), 'sha256:x', 0);
        $this->assertNotFound(static fn () => $host('019a6b2c-7f00-7a11-8b22-3c44d55e6f04'));
        $this->assertNotFound(static fn () => $image('019a6b31-7e00-7f12-a3b4-c5d6e7f80904'));

        $this->guest = true;
        self::assertSame('web-01', $host(Inv::HOST)->hostname, 'It carries an application of Shop.');
        self::assertSame(Inv::IMAGE, $image(self::IMAGE_RECORD)->imageId);
        $this->assertNotFound(static fn () => $host(self::HOST2));
        $this->assertNotFound(static fn () => $host('019a6b2c-7f00-7a11-8b22-3c44d55e6f03'));
        $this->read->apps = [self::row(Inv::APP, self::BLOG_ENV, Inv::HOST, Inv::IMAGE)];
        $this->assertNotFound(static fn () => $image(self::IMAGE_RECORD));
    }

    #[Test]
    public function it_should_list_the_packages_of_an_application_an_image_or_a_host(): void
    {
        $packages = fn (string $of, string $id, string $team = Inv::TEAM): array => array_map(
            static fn (PackageRow $p): string => $p->purl,
            (new ListPackagesHandler($this->visibility(), new GetApplicationHandler($this->access(), $this->read), $this->read))(new ListPackages($team, $of, $id))->items,
        );

        self::assertSame(['pkg:composer/symfony/http-kernel@6.4.3'], $packages(ListPackages::APPLICATION, Inv::APP));
        self::assertSame(['pkg:deb/debian/openssl@3', 'pkg:deb/debian/zlib@1'], $packages(ListPackages::IMAGE, self::IMAGE_RECORD));
        self::assertSame([], $packages(ListPackages::HOST, Inv::HOST));
        $this->read->packages[Inv::TEAM.' sha256:os'] = [new PackageRow('pkg:deb/debian/bash@5', 'deb', 'debian', 'bash', '5', null, null)];
        self::assertSame(['pkg:deb/debian/bash@5'], $packages(ListPackages::HOST, Inv::HOST));
        $none = (new ListPackagesHandler($this->visibility(), new GetApplicationHandler($this->access(), $this->read), $this->read))(new ListPackages(Inv::TEAM, ListPackages::HOST, self::HOST2));
        self::assertSame([[], 0, 0], [$none->items, $none->filtered, $none->total], 'No OS SBOM.');
        $this->assertNotFound(static fn () => $packages('other', Inv::APP));
        $this->read->apps[] = new AppRow('019a6b31-6e00-7f12-a3b4-c5d6e7f80904', Inv::OTHER_TEAM, Inv::HOST, 'x', 'k', 'path', [], null, null, 'none', true, Inv::now(), 'sha256:app', 1);
        $this->assertNotFound(static fn () => $packages(ListPackages::APPLICATION, Inv::APP, Inv::OTHER_TEAM));
    }

    #[Test]
    public function it_should_count_the_inventory_the_actor_sees(): void
    {
        $count = fn (): InventoryCounts => (new CountInventoryHandler($this->access(), $this->read))(new CountInventory(Inv::TEAM));
        $this->read->apps[] = new AppRow('019a6b31-6e00-7f12-a3b4-c5d6e7f80905', Inv::TEAM, Inv::HOST, 'web', 'k', 'path', [], null, null, 'none', false, Inv::now(), 'sha256:app', 1);
        $this->read->apps[] = new AppRow('019a6b31-6e00-7f12-a3b4-c5d6e7f80906', Inv::OTHER_TEAM, Inv::HOST, 'web', 'k', 'path', [], null, Inv::ENVIRONMENT, 'auto', true, Inv::now(), 'sha256:app', 1);
        $this->read->images[] = new ImageView('019a6b31-7e00-7f12-a3b4-c5d6e7f80905', Inv::OTHER_TEAM, Inv::IMAGE, [], [], null, null, Inv::now(), 'sha256:x', 0);

        $member = $count();
        self::assertSame([3, 1, 1], [$member->applications, $member->unattached, $member->images], 'Gone and other teams not counted.');

        $this->guest = true;
        $guest = $count();
        self::assertSame([1, null, null], [$guest->applications, $guest->unattached, $guest->images], 'Shop only; neither the unattached nor the images.');
        $this->assertNotFound(fn () => (new CountInventoryHandler($this->access(), $this->read))(new CountInventory(Inv::OTHER_TEAM)));
    }

    #[Test]
    public function it_should_list_the_history_of_who_sees_the_host_or_the_application(): void
    {
        $at = Inv::now();
        $this->read->history = [
            Inv::HOST => [new HistoryRow($at, 'host_packages', 'sha256:os', false, 'scan_now'), new HistoryRow($at, 'host_facts', 'sha256:f', true, null), new HistoryRow($at, 'image', 'sha256:i', true, null)],
            Inv::APP => [new HistoryRow($at, 'application', 'sha256:app', true, 'scheduled')],
            self::BLOG_APP => [new HistoryRow($at, 'application', 'sha256:blog', true, 'scheduled')],
        ];
        $history = fn (string $of, string $id, string $team = Inv::TEAM): array => array_map(
            static fn (HistoryRow $r): string => $r->subject.' '.$r->digest,
            (new ListInventoryHistoryHandler($this->visibility(), new GetApplicationHandler($this->access(), $this->read), $this->read))(new ListInventoryHistory($team, $of, $id))->items,
        );

        self::assertSame(['host_packages sha256:os', 'host_facts sha256:f'], $history(ListInventoryHistory::HOST, Inv::HOST));
        self::assertSame(['application sha256:app'], $history(ListInventoryHistory::APPLICATION, Inv::APP));
        $this->assertNotFound(static fn () => $history('image', Inv::HOST));
        $this->assertNotFound(static fn () => $history(ListInventoryHistory::APPLICATION, Inv::APP, Inv::OTHER_TEAM));
        $this->assertNotFound(static fn () => $history(ListInventoryHistory::HOST, Inv::HOST, Inv::OTHER_TEAM));

        $this->guest = true;
        self::assertSame(['application sha256:app'], $history(ListInventoryHistory::APPLICATION, Inv::APP));
        $this->assertNotFound(static fn () => $history(ListInventoryHistory::APPLICATION, self::BLOG_APP));
        $this->assertNotFound(static fn () => $history(ListInventoryHistory::HOST, self::HOST2));
    }

    #[Test]
    public function it_should_document_an_application_with_the_permission_of_where_it_is(): void
    {
        $apps = new InMemoryApps();
        $app = AppInstance::discover(Uuid::fromString(Inv::APP), Uuid::fromString(Inv::TEAM), Uuid::fromString(Inv::HOST), Uuid::fromString(Inv::AGENT), Inv::compose(), Inv::now());
        $apps->add($app);
        $urls = static fn (): array => array_map(static fn (DocumentationLink $l): string => $l->url, $app->links());
        $runbook = [['url' => 'https://wiki.example.com/shop', 'label' => 'Runbook']];

        $command = new DocumentApplication(Inv::APP, [...$runbook, ['url' => 'https://e.com', 'label' => null]]);
        self::assertSame(['inventory.document_application', ['links'], ['links' => 2]], [$command->auditAction()->name, $command->auditAction()->allowedContextKeys, $command->auditContext()->values]);
        self::assertEquals(Target::of('application', Inv::APP), $command->auditTarget());

        $this->assertDenied('host.edit', fn () => $this->document($apps, $runbook), 'Not attached: host.edit on the team.');
        $this->editsHosts = true;
        $this->document($apps, $runbook);
        self::assertSame(['https://wiki.example.com/shop'], $urls());
        self::assertSame('Runbook', $app->links()[0]->label);

        $app->assign(Uuid::fromString(Inv::ENVIRONMENT));
        $this->assignIn = [self::BLOG];
        $this->assertDenied('project.apps.assign', fn () => $this->document($apps, []), 'Attached: project.apps.assign on its project, host.edit is not enough.');
        $this->assignIn = [self::SHOP];
        $this->editsHosts = false;
        $this->document($apps, []);
        self::assertSame([], $urls());
        $this->assertRefused(InvalidDocumentationLink::class, fn () => $this->document($apps, [['url' => 'ftp://e.com', 'label' => null]]));
        $this->assertNotFound(fn () => $this->document($apps, [], 'nope'));

        $view = fn (string $id): ApplicationView => (new GetApplicationHandler($this->access(), $this->read))(new GetApplication($id));
        self::assertTrue($view(Inv::APP)->mayDocument, 'Attached to Shop, where it may assign.');
        $this->assignIn = [self::BLOG];
        $this->editsHosts = true;
        self::assertFalse($view(Inv::APP)->mayDocument);
        self::assertTrue($view(self::LOOSE_APP)->mayDocument, 'Not attached: host.edit.');
        $this->editsHosts = false;
        self::assertFalse($view(self::LOOSE_APP)->mayDocument);
    }

    #[Test]
    public function it_should_copy_the_label_of_a_host_and_show_it(): void
    {
        $labels = new InMemoryHostLabels();
        $commands = new RecordingCommands();
        new CopyHostLabel($commands)(new HostLabelled(Inv::HOST, Inv::TEAM, 'Shop front', ['10.0.0.1']));
        self::assertEquals([new RecordHostLabel(Inv::HOST, Inv::TEAM, 'Shop front', ['10.0.0.1'])], $commands->dispatched);

        $record = new RecordHostLabelHandler($labels);
        $record(new RecordHostLabel(Inv::HOST, Inv::TEAM, 'Shop front', ['10.0.0.1']));
        $label = $labels->find(Uuid::fromString(Inv::HOST));
        self::assertSame([Inv::TEAM, 'Shop front', ['10.0.0.1']], [$label?->teamId()->toRfc4122(), $label?->displayName(), $label?->addresses()]);
        $record(new RecordHostLabel(Inv::HOST, Inv::TEAM, null, []));
        self::assertCount(1, $labels->labels, 'Changed in place.');
        self::assertSame([null, []], [$label?->displayName(), $label?->addresses()]);
        $command = new RecordHostLabel(Inv::HOST, Inv::TEAM, null, []);
        self::assertSame(['inventory.record_host_label', []], [$command->auditAction()->name, $command->auditContext()->values]);
        self::assertEquals(Target::of('host', Inv::HOST), $command->auditTarget());

        self::assertSame('web-01', new HostView(Inv::HOST, Inv::TEAM, 'web-01', [], null, null, 0)->name());
        self::assertSame('Shop front', new HostView(Inv::HOST, Inv::TEAM, 'web-01', [], null, null, 0, 'Shop front')->name());

        $host = fn (): HostView => (new GetHostInventoryHandler($this->visibility()))(new GetHostInventory(Inv::TEAM, '019a6b2c-7f00-7a11-8b22-3c44d55e6f03'));
        self::assertSame(['web-03', null, []], [$host()->name(), $host()->displayName, $host()->addresses], 'Never inventoried, never labelled.');
        $this->read->labels['019a6b2c-7f00-7a11-8b22-3c44d55e6f03'] = ['displayName' => 'Batch', 'addresses' => ['10.0.0.3']];
        self::assertSame(['Batch', 'web-03', ['10.0.0.3']], [$host()->name(), $host()->hostname, $host()->addresses], 'Never inventoried: its label still.');
    }

    #[Test]
    public function it_should_attach_an_application_with_the_permission_on_both_projects(): void
    {
        $apps = new InMemoryApps();
        $app = AppInstance::discover(Uuid::fromString(Inv::APP), Uuid::fromString(Inv::TEAM), Uuid::fromString(Inv::HOST), Uuid::fromString(Inv::AGENT), Inv::compose(), Inv::now());
        $apps->add($app);
        $attach = fn (string $environment, string $id = Inv::APP) => (new AttachApplicationHandler(new ApplicationAttachment($this->access(), $apps)))(new AttachApplication($id, $environment));
        $detach = fn () => (new DetachApplicationHandler(new ApplicationAttachment($this->access(), $apps)))(new DetachApplication(Inv::APP));
        $state = static fn (): array => [$app->environmentId()?->toRfc4122(), $app->assignment()];

        $command = new AttachApplication(Inv::APP, self::BLOG_ENV);
        self::assertSame(['inventory.attach_application', ['environment_id'], ['environment_id' => self::BLOG_ENV], Inv::APP], [$command->auditAction()->name, $command->auditAction()->allowedContextKeys, $command->auditContext()->values, $command->appId]);
        $detach();
        self::assertSame([null, Assignment::None], $state(), 'Nothing to detach: left to the automatic rules.');
        $this->assertRefused(EnvironmentOfAnotherTeam::class, static fn () => $attach('019a6b2c-4f00-7a11-8b22-3c44d55e6f09'));
        $this->assertNotFound(static fn () => $attach(Inv::ENVIRONMENT, 'nope'));
        $this->assertNotFound(static fn () => $attach(Inv::ENVIRONMENT, self::BLOG_APP));
        $this->assignIn = [self::BLOG];
        $this->assertRefused(PermissionDenied::class, static fn () => $attach(Inv::ENVIRONMENT));
        self::assertSame([null, Assignment::None], $state());

        $attach(self::BLOG_ENV);
        self::assertSame([self::BLOG_ENV, Assignment::Manual], $state());
        self::assertEquals([new ApplicationPlaced(Inv::APP, Inv::TEAM)], $app->releaseEvents(), 'Its findings move with it.');
        $this->assignIn = [self::SHOP];
        $this->assertRefused(PermissionDenied::class, static fn () => $attach(Inv::ENVIRONMENT), 'Moving it out of Blog needs Blog too.');
        $this->assertRefused(PermissionDenied::class, $detach);
        self::assertSame([self::BLOG_ENV, Assignment::Manual], $state());

        $this->assignIn = [self::SHOP, self::BLOG];
        $this->guest = true;
        $this->assertNotFound(static fn () => $attach(Inv::ENVIRONMENT), 'Attached to a project it does not see.');
        $this->guest = false;
        $attach(Inv::ENVIRONMENT);
        $this->guest = true;
        $this->assertRefused(EnvironmentOfAnotherTeam::class, static fn () => $attach(self::BLOG_ENV));
        $detach();
        self::assertSame([null, Assignment::Manual], $state());
        self::assertCount(2, array_filter($app->releaseEvents(), static fn (object $e): bool => $e instanceof ApplicationPlaced), 'Moved to Shop, then detached.');
    }

    private function access(): InventoryAccess
    {
        $checker = new readonly class($this) implements PermissionChecker {
            public function __construct(private TeamViewsTest $test)
            {
            }

            public function isGranted(Permission $permission, ?string $teamId = null, ?string $projectId = null): bool
            {
                if (Permission::HostEdit === $permission) {
                    return $this->test->editsHosts && Inv::TEAM === $teamId && null === $projectId;
                }

                return Permission::ProjectAppsAssign === $permission && Inv::TEAM === $teamId && \in_array($projectId, $this->test->assignable(), true);
            }
        };

        return new InventoryAccess($this->queries, $checker);
    }

    /**
     * @return list<string>
     */
    public function assignable(): array
    {
        return $this->assignIn;
    }

    private function visibility(): InventoryVisibility
    {
        return new InventoryVisibility($this->access(), $this->read, $this->queries);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function named(array $fields): AppRow
    {
        return new AppRow(Inv::APP, Inv::TEAM, Inv::HOST, 'web-01', 'compose:shop/php', 'compose_service', $fields, null, null, 'none', true, Inv::now(), 'sha256:a', 2);
    }

    private static function row(string $id, ?string $environment, string $host, ?string $image = null): AppRow
    {
        return new AppRow($id, Inv::TEAM, $host, 'web', 'compose:'.$id, 'compose_service', [], $image, $environment, null === $environment ? 'none' : 'auto', true, Inv::now(), 'sha256:app', 1);
    }

    private function assertNotFound(\Closure $call, string $message = ''): void
    {
        $this->assertRefused(ResourceNotFound::class, $call, $message);
    }

    /**
     * @param Page<MatrixRow> $page
     *
     * @return list<string>
     */
    private static function matrixLines(Page $page): array
    {
        return array_map(static fn (MatrixRow $r): string => \sprintf('%s %s %s %s %d %s', $r->kind, $r->ecosystem, $r->component, $r->version, $r->versions, implode(',', array_map(static fn (array $p): string => $p['application'].'@'.$p['host'], $r->places))), $page->items);
    }

    private function assertDenied(string $permission, \Closure $call, string $message = ''): void
    {
        try {
            $call();
        } catch (PermissionDenied $e) {
            self::assertSame(\sprintf('Permission "%s" required.', $permission), $e->getMessage(), $message);

            return;
        }

        self::fail('Expected a denial. '.$message);
    }

    /**
     * @param list<array{url: string, label: ?string}> $links
     */
    private function document(InMemoryApps $apps, array $links, string $id = Inv::APP): void
    {
        (new DocumentApplicationHandler(new ApplicationAttachment($this->access(), $apps), $this->access()))(new DocumentApplication($id, $links));
    }

    /**
     * @param class-string<\Throwable> $class
     */
    private function assertRefused(string $class, \Closure $call, string $message = ''): void
    {
        try {
            $call();
        } catch (\Throwable $e) {
            self::assertInstanceOf($class, $e, $message);

            return;
        }

        self::fail('Expected '.$class.'. '.$message);
    }
}
