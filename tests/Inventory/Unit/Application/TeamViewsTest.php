<?php

declare(strict_types=1);

namespace Tests\Inventory\Unit\Application;

use Inventory\Application\Command\Application\AttachApplication;
use Inventory\Application\Command\Application\DetachApplication;
use Inventory\Application\Command\Application\Handler\ApplicationAttachment;
use Inventory\Application\Command\Application\Handler\AttachApplicationHandler;
use Inventory\Application\Command\Application\Handler\DetachApplicationHandler;
use Inventory\Application\Query\Team\GetApplication;
use Inventory\Application\Query\Team\GetHostInventory;
use Inventory\Application\Query\Team\GetImage;
use Inventory\Application\Query\Team\Handler\GetApplicationHandler;
use Inventory\Application\Query\Team\Handler\GetHostInventoryHandler;
use Inventory\Application\Query\Team\Handler\GetImageHandler;
use Inventory\Application\Query\Team\Handler\InventoryVisibility;
use Inventory\Application\Query\Team\Handler\ListApplicationsHandler;
use Inventory\Application\Query\Team\Handler\ListPackagesHandler;
use Inventory\Application\Query\Team\ListApplications;
use Inventory\Application\Query\Team\ListPackages;
use Inventory\Application\Query\View\AppRow;
use Inventory\Application\Query\View\HostView;
use Inventory\Application\Query\View\ImageView;
use Inventory\Application\Query\View\PackageRow;
use Inventory\Application\Security\InventoryAccess;
use Inventory\Application\Security\InventoryScope;
use Inventory\Domain\Model\AppInstance;
use Inventory\Domain\Model\Assignment;
use Inventory\Domain\Model\EnvironmentOfAnotherTeam;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Authorization\PermissionChecker;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Contract\Fleet\FindTeamHost;
use Shared\Application\Contract\Organization\GetVisibleProjects;
use Shared\Application\Contract\Organization\ListTeamEnvironments;
use Shared\Application\Contract\Organization\TeamEnvironment;
use Shared\Application\Contract\Organization\VisibleProjects;
use Shared\Application\Query\PageRequest;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;
use Tests\Inventory\Support\InMemoryApps;
use Tests\Inventory\Support\InMemoryReadModel;
use Tests\Inventory\Support\Inv;
use Tests\Organization\Support\FixedQueries;

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
        $named = $row(['path' => '/srv/app'])->withEnvironment('Shop / production');
        self::assertSame(['Shop / production', '/srv/app', Inv::APP, 2, 'sha256:a', 'none', true, 'web-01'], [$named->environment, $named->name(), $named->id, $named->packages, $named->sbomDigest, $named->assignment, $named->present, $named->hostname]);
        self::assertSame([Inv::TEAM, Inv::HOST, 'compose:shop/php', 'compose_service', null, null, '2026-11-09 08:02'], [$named->teamId, $named->hostId, $named->subjectKey, $named->kind, $named->imageId, $named->environmentId, $named->lastSeenAt->format('Y-m-d H:i')]);

        $host = new HostView(Inv::HOST, Inv::TEAM, 'web-01', [
            'os' => ['pretty_name' => 'Debian GNU/Linux 12'], 'kernel' => '6.1.0', 'arch' => 7,
            'runtimes' => [['name' => 'php', 'version' => '8.4.1', 'service' => 'php'], ['name' => 'node', 'version' => '22.11.0'], ['name' => 'go'], ['version' => '1'], 'x'],
            'docker' => ['engine_version' => '27.3.1'],
        ], null, null, 0);
        self::assertSame(['Debian GNU/Linux 12', '6.1.0', null, null, '27.3.1'], [$host->os(), $host->fact('kernel'), $host->fact('arch'), $host->fact('missing'), $host->dockerVersion()]);
        self::assertSame([['name' => 'php', 'version' => '8.4.1', 'service' => 'php'], ['name' => 'node', 'version' => '22.11.0', 'service' => null]], $host->runtimes());
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

        $this->guest = true;
        self::assertSame([Inv::APP.' Shop / production'], $list());
        self::assertSame([Inv::APP.' Shop / production'], $list(self::SHOP));
        $this->read->lastFilter = [];
        $none = (new ListApplicationsHandler($this->access(), $this->read))(new ListApplications(Inv::TEAM, unattachedOnly: true));
        self::assertSame([[], 0, 0], [$none->items, $none->filtered, $none->total]);
        self::assertSame([], $this->read->lastFilter, 'Never asked.');
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
    }

    private function access(): InventoryAccess
    {
        $checker = new readonly class($this) implements PermissionChecker {
            public function __construct(private TeamViewsTest $test)
            {
            }

            public function isGranted(Permission $permission, ?string $teamId = null, ?string $projectId = null): bool
            {
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
