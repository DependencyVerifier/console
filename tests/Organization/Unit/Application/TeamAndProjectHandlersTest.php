<?php

declare(strict_types=1);

namespace Tests\Organization\Unit\Application;

use Organization\Application\Command\Environment\CreateEnvironment;
use Organization\Application\Command\Environment\DeleteEnvironment;
use Organization\Application\Command\Environment\Handler\CreateEnvironmentHandler;
use Organization\Application\Command\Environment\Handler\DeleteEnvironmentHandler;
use Organization\Application\Command\Environment\Handler\EnvironmentAccess;
use Organization\Application\Command\Environment\Handler\UpdateEnvironmentHandler;
use Organization\Application\Command\Environment\UpdateEnvironment;
use Organization\Application\Command\Instance\Handler\UpdateDefaultQuotasHandler;
use Organization\Application\Command\Instance\UpdateDefaultQuotas;
use Organization\Application\Command\Project\CreateProject;
use Organization\Application\Command\Project\DeleteProject;
use Organization\Application\Command\Project\Handler\CreateProjectHandler;
use Organization\Application\Command\Project\Handler\DeleteProjectHandler;
use Organization\Application\Command\Project\Handler\RenameProjectHandler;
use Organization\Application\Command\Project\RenameProject;
use Organization\Application\Command\QuotaInput;
use Organization\Application\Command\Team\CreateTeam;
use Organization\Application\Command\Team\DeleteTeam;
use Organization\Application\Command\Team\Handler\CreateTeamHandler;
use Organization\Application\Command\Team\Handler\DeleteTeamHandler;
use Organization\Application\Command\Team\Handler\RenameTeamHandler;
use Organization\Application\Command\Team\Handler\UpdateTeamHandler;
use Organization\Application\Command\Team\RenameTeam;
use Organization\Application\Command\Team\UpdateTeam;
use Organization\Application\EventHandler\SendTeamAdminAppointment;
use Organization\Application\Port\TeamAdminAppointmentSender;
use Organization\Domain\Authorization\TeamGrant;
use Organization\Domain\Event\TeamAdminAppointed;
use Organization\Domain\Event\TeamCreated;
use Organization\Domain\Model\EnvironmentId;
use Organization\Domain\Model\EnvironmentNotEmpty;
use Organization\Domain\Model\GrantChange;
use Organization\Domain\Model\Invitation;
use Organization\Domain\Model\InvitationId;
use Organization\Domain\Model\InvitationTarget;
use Organization\Domain\Model\ProjectId;
use Organization\Domain\Model\ProjectNotEmpty;
use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\QuotaExceeded;
use Organization\Domain\Model\QuotaName;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Contract\Identity\AccountView;
use Shared\Application\Contract\Identity\FindAccounts;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;
use Tests\Organization\Support\FixedAccounts;
use Tests\Organization\Support\FixedPermissions;
use Tests\Organization\Support\FixedQueries;
use Tests\Organization\Support\FixedSlugs;
use Tests\Organization\Support\RecordingContentProbe;
use Tests\Organization\Support\Teams;
use Tests\Organization\Support\World;

final class TeamAndProjectHandlersTest extends TestCase
{
    private const string ENV = '019a6b2c-4f00-7a11-8b22-3c44d55e6f01';
    private const string INVITATION = '019a6b2c-5f00-7a11-8b22-3c44d55e6f01';
    private const string NEW_USER = '019a6b2c-1f00-7a11-8b22-3c44d55e6f09';
    private const string SETUP = '019a6b2c-6f00-7a11-8b22-3c44d55e6f01';

    /**
     * @return array<string, ?int>
     */
    private static function quotas(int $value): array
    {
        $quotas = [];
        foreach (QuotaName::cases() as $name) {
            $quotas[$name->value] = $value;
        }

        return [...$quotas, QuotaName::MaxAgents->value => null];
    }

    #[Test]
    public function it_should_create_a_team_with_the_defaults_and_its_first_admin_at_once(): void
    {
        $world = new World()->asSuperAdmin();
        $world->settings->settings->changeDefaultQuotas(QuotaInput::teamQuotas(self::quotas(7)));
        $world->teams->add(Teams::team(Teams::OTHER_TEAM));
        $accounts = new FixedAccounts(['jane@example.com' => Teams::MEMBER]);

        $this->createTeam($world, $accounts)(new CreateTeam(Teams::TEAM, 'Platform', 'jane@example.com', self::NEW_USER, self::SETUP));

        self::assertSame([['jane@example.com', self::NEW_USER, self::SETUP]], $accounts->asked);
        $team = $world->teams->find(TeamId::fromString(Teams::TEAM));
        self::assertNotNull($team);
        self::assertSame('platform-2', $team->slug(), 'Unique slug.');
        self::assertSame(7, $team->quotas()->get(QuotaName::MaxProjects)->limit());
        self::assertTrue($team->isAdminEstablished());
        self::assertSame(TeamRole::Admin, $team->membershipOf(Uuid::fromString(Teams::MEMBER))->role());
        self::assertSame([], $world->invitations->invitations, 'No invitation to accept.');
        self::assertCount(1, $world->grants->grants);
        self::assertSame(GrantChange::RoleAssigned, $world->grants->grants[0]->change());
        self::assertSame('team_admin', $world->grants->grants[0]->value());
        self::assertTrue($world->grants->grants[0]->userId()->equals(Uuid::fromString(Teams::MEMBER)));
        self::assertEquals([new TeamCreated(Teams::TEAM), new TeamAdminAppointed(Teams::TEAM, Teams::MEMBER, 'Platform')], $team->releaseEvents());
    }

    #[Test]
    public function it_should_admit_an_invited_account_under_the_id_given_by_the_command(): void
    {
        $world = new World()->asSuperAdmin();
        $world->settings->settings->changeDefaultQuotas(QuotaInput::teamQuotas([...self::quotas(5), QuotaName::MaxMembers->value => 1]));

        $this->createTeam($world, new FixedAccounts())(new CreateTeam(Teams::TEAM, 'Platform', 'new@example.com', self::NEW_USER, self::SETUP));

        $team = $world->teams->find(TeamId::fromString(Teams::TEAM));
        self::assertNotNull($team, 'Room for exactly the first admin.');
        self::assertSame(TeamRole::Admin, $team->membershipOf(Uuid::fromString(self::NEW_USER))->role());
    }

    #[Test]
    public function it_should_refuse_a_team_without_member_quota_a_refused_account_or_permission(): void
    {
        $world = new World()->asSuperAdmin();
        $world->settings->settings->changeDefaultQuotas(QuotaInput::teamQuotas([...self::quotas(5), QuotaName::MaxMembers->value => 0]));
        $accounts = new FixedAccounts();
        try {
            $this->createTeam($world, $accounts)(new CreateTeam(Teams::TEAM, 'Platform', 'jane@example.com', self::NEW_USER, self::SETUP));
            self::fail('No member allowed.');
        } catch (QuotaExceeded) {
        }
        self::assertSame([], $accounts->asked, 'Checked before any account is invited.');
        self::assertSame([], $world->teams->teams);

        $world->settings->settings->changeDefaultQuotas(QuotaInput::teamQuotas(self::quotas(5)));
        $refusal = new \DomainException('refused by Identity');
        try {
            $this->createTeam($world, new FixedAccounts(refusal: $refusal))(new CreateTeam(Teams::TEAM, 'Platform', 'off@example.com', self::NEW_USER, self::SETUP));
            self::fail('Refused account.');
        } catch (\DomainException $caught) {
            self::assertSame($refusal, $caught);
        }
        self::assertSame([], $world->teams->teams);
        self::assertSame([], $world->grants->grants);

        $accounts = new FixedAccounts();
        try {
            $this->createTeam(new World()->as(Teams::ADMIN, TeamRole::Admin), $accounts)(new CreateTeam(Teams::TEAM, 'Platform', 'jane@example.com', self::NEW_USER, self::SETUP));
            self::fail('Super admin only.');
        } catch (PermissionDenied) {
        }
        self::assertSame([], $accounts->asked);
    }

    #[Test]
    public function it_should_tell_the_first_admin_by_email(): void
    {
        $sender = new class implements TeamAdminAppointmentSender {
            /** @var list<array{string, string, string}> */
            public array $sent = [];

            public function send(string $email, string $teamId, string $teamName): void
            {
                $this->sent[] = [$email, $teamId, $teamName];
            }
        };
        $queries = new FixedQueries([FindAccounts::class => static fn (object $query): array => $query instanceof FindAccounts && [Teams::MEMBER] === $query->userIds ? [Teams::MEMBER => new AccountView(Teams::MEMBER, 'jane@example.com', true)] : []]);
        $handler = new SendTeamAdminAppointment($queries, $sender);

        $handler(new TeamAdminAppointed(Teams::TEAM, Teams::MEMBER, 'Platform'));
        $handler(new TeamAdminAppointed(Teams::TEAM, Teams::GUEST, 'Platform'));

        self::assertSame([['jane@example.com', Teams::TEAM, 'Platform']], $sender->sent, 'Nobody to tell for an unknown account.');
    }

    #[Test]
    public function it_should_delete_a_team_with_its_projects_and_revoke_its_pending_invitations(): void
    {
        $world = new World()->asSuperAdmin();
        $team = Teams::withAdmin();
        $other = Teams::team(Teams::OTHER_TEAM);
        $world->teams->add($team);
        $world->teams->add($other);
        $world->projects->add(Teams::project());
        $world->projects->add(Teams::project(Teams::TEAM, '019a6b2c-3f00-7a11-8b22-3c44d55e6f02'));
        $kept = Teams::project(Teams::OTHER_TEAM, '019a6b2c-3f00-7a11-8b22-3c44d55e6f03');
        $world->projects->add($kept);
        $issue = static fn (string $id, InvitationTarget $target): Invitation => Invitation::issue(InvitationId::fromString($id), 'jane@example.com', $target, Uuid::fromString(Teams::ADMIN), 't', 'h:'.$id, $world->clock->now());
        $teamInvitation = $issue(self::INVITATION, InvitationTarget::team(TeamId::fromString(Teams::TEAM), TeamRole::Member));
        $projectInvitation = $issue('019a6b2c-5f00-7a11-8b22-3c44d55e6f02', InvitationTarget::project(TeamId::fromString(Teams::TEAM), ProjectId::fromString(Teams::PROJECT), ProjectRole::Viewer));
        $otherInvitation = $issue('019a6b2c-5f00-7a11-8b22-3c44d55e6f03', InvitationTarget::team(TeamId::fromString(Teams::OTHER_TEAM), TeamRole::Member));
        array_map($world->invitations->add(...), [$teamInvitation, $projectInvitation, $otherInvitation]);

        $this->deleteTeam($world)(new DeleteTeam(Teams::TEAM));

        self::assertSame([Teams::OTHER_TEAM], array_keys($world->teams->teams));
        self::assertSame([Teams::TEAM], $world->teams->locked);
        self::assertSame([$kept], array_values($world->projects->projects), 'Every project of the team goes, never another team\'s.');
        self::assertTrue($teamInvitation->isRevoked());
        self::assertTrue($projectInvitation->isRevoked());
        self::assertFalse($otherInvitation->isRevoked());
    }

    #[Test]
    public function it_should_refuse_to_delete_a_team_without_the_permission_or_an_unknown_one(): void
    {
        $world = new World()->as(Teams::ADMIN, TeamRole::Admin);
        $world->teams->add(Teams::withAdmin());
        try {
            $this->deleteTeam($world)(new DeleteTeam(Teams::TEAM));
            self::fail('Super admin only.');
        } catch (PermissionDenied) {
        }
        self::assertCount(1, $world->teams->teams);
        self::assertSame([], $world->teams->locked);

        $this->expectException(ResourceNotFound::class);
        $this->deleteTeam(new World()->asSuperAdmin())(new DeleteTeam(Teams::TEAM));
    }

    #[Test]
    public function it_should_rename_a_team_and_change_its_quotas(): void
    {
        $world = new World()->as(Teams::ADMIN, TeamRole::Admin);
        $team = Teams::withAdmin();
        $world->teams->add($team);

        new RenameTeamHandler($world->access())(new RenameTeam(Teams::TEAM, 'Payments'));
        self::assertSame('Payments', $team->name());

        try {
            new UpdateTeamHandler($world->access())(new UpdateTeam(Teams::TEAM, 'Checkout', self::quotas(1)));
            self::fail('Super admin only.');
        } catch (PermissionDenied) {
        }

        $world->asSuperAdmin();
        new UpdateTeamHandler($world->access())(new UpdateTeam(Teams::TEAM, 'Checkout', self::quotas(1)));
        self::assertSame('Checkout', $team->name());
        self::assertSame(1, $team->quotas()->get(QuotaName::MaxProjects)->limit());
        self::assertTrue($team->quotas()->get(QuotaName::MaxAgents)->isUnlimited());

        new UpdateDefaultQuotasHandler($world->access(), $world->settings)(new UpdateDefaultQuotas(self::quotas(4)));
        self::assertSame(4, $world->settings->settings->defaultQuotas()->get(QuotaName::MaxMembers)->limit());

        $member = new World()->as(Teams::MEMBER, TeamRole::Member);
        $member->teams->add($team);
        $this->expectException(PermissionDenied::class);
        new RenameTeamHandler($member->access())(new RenameTeam(Teams::TEAM, 'x'));
    }

    #[Test]
    public function it_should_require_every_quota_and_super_admin_for_defaults(): void
    {
        try {
            QuotaInput::teamQuotas([QuotaName::MaxProjects->value => 1]);
            self::fail('Missing quotas.');
        } catch (\InvalidArgumentException $missing) {
            self::assertSame('Quota "max_environments_per_project" is missing.', $missing->getMessage());
        }

        $this->expectException(PermissionDenied::class);
        $world = new World()->as(Teams::ADMIN, TeamRole::Admin);
        new UpdateDefaultQuotasHandler($world->access(), $world->settings)(new UpdateDefaultQuotas(self::quotas(4)));
    }

    #[Test]
    public function it_should_create_projects_within_the_quota(): void
    {
        $world = new World()->as(Teams::MEMBER, TeamRole::Member);
        $team = Teams::withAdmin();
        $world->teams->add($team);
        $world->permissions = new FixedPermissions(false, [new TeamGrant(Teams::TEAM, TeamRole::Member, [Permission::ProjectCreate])]);
        $team->changeQuotas(QuotaInput::teamQuotas(self::quotas(1)));
        $handler = new CreateProjectHandler($world->access(), $world->projects, new FixedSlugs(), $world->clock);

        $handler(new CreateProject(Teams::PROJECT, Teams::TEAM, 'Shop'));
        $project = $world->projects->find(ProjectId::fromString(Teams::PROJECT));
        self::assertNotNull($project);
        self::assertSame('shop', $project->slug());
        self::assertSame(ProjectRole::Admin, $project->membershipOf(Uuid::fromString(Teams::MEMBER))->role(), 'The member creator becomes project admin.');

        $this->expectException(QuotaExceeded::class);
        $handler(new CreateProject(Uuid::v7()->toRfc4122(), Teams::TEAM, 'Shop'));
    }

    #[Test]
    public function it_should_suffix_a_taken_project_slug_and_refuse_without_permission(): void
    {
        $world = new World()->as(Teams::ADMIN, TeamRole::Admin);
        $world->teams->add(Teams::withAdmin());
        $world->projects->add(Teams::project());
        $handler = new CreateProjectHandler($world->access(), $world->projects, new FixedSlugs(), $world->clock);

        $id = Uuid::v7()->toRfc4122();
        $handler(new CreateProject($id, Teams::TEAM, 'Shop'));
        self::assertSame('shop-2', $world->projects->find(ProjectId::fromString($id))?->slug());

        $this->expectException(PermissionDenied::class);
        $world->as(Teams::MEMBER, TeamRole::Member);
        new CreateProjectHandler($world->access(), $world->projects, new FixedSlugs(), $world->clock)(new CreateProject(Uuid::v7()->toRfc4122(), Teams::TEAM, 'X'));
    }

    #[Test]
    public function it_should_rename_and_delete_an_empty_project_revoking_its_invitations(): void
    {
        $world = new World()->as(Teams::ADMIN, TeamRole::Admin);
        $world->teams->add(Teams::withAdmin());
        $project = Teams::project();
        $world->projects->add($project);
        $pending = Invitation::issue(InvitationId::fromString(self::INVITATION), 'jane@example.com', InvitationTarget::project(TeamId::fromString(Teams::TEAM), ProjectId::fromString(Teams::PROJECT), ProjectRole::Viewer), Uuid::fromString(Teams::ADMIN), 't', 'h', $world->clock->now());
        $world->invitations->add($pending);

        new RenameProjectHandler($world->access())(new RenameProject(Teams::PROJECT, 'Store'));
        self::assertSame('Store', $project->name());

        new DeleteProjectHandler($world->access(), $world->projects, $world->invitations, [])(new DeleteProject(Teams::PROJECT));
        self::assertNull($world->projects->find(ProjectId::fromString(Teams::PROJECT)));
        self::assertTrue($pending->isRevoked());
        self::assertContains(Teams::TEAM, $world->teams->locked);
    }

    #[Test]
    public function it_should_refuse_to_delete_a_project_with_content(): void
    {
        $world = new World()->as(Teams::ADMIN, TeamRole::Admin);
        $world->teams->add(Teams::withAdmin());
        $project = Teams::project();
        $world->projects->add($project);
        $probe = $this->probe(project: true);

        try {
            new DeleteProjectHandler($world->access(), $world->projects, $world->invitations, [$this->probe(), $probe])(new DeleteProject(Teams::PROJECT));
            self::fail('Content declared.');
        } catch (ProjectNotEmpty) {
            self::assertSame([Teams::PROJECT], $probe->asked);
        }

        $project->addEnvironment(EnvironmentId::fromString(self::ENV), 'prod', true);
        $this->expectException(ProjectNotEmpty::class);
        new DeleteProjectHandler($world->access(), $world->projects, $world->invitations, [$this->probe()])(new DeleteProject(Teams::PROJECT));
    }

    #[Test]
    public function it_should_require_the_project_permissions(): void
    {
        $world = new World()->as(Teams::MEMBER, TeamRole::Viewer);
        $world->teams->add(Teams::withAdmin());
        $world->projects->add(Teams::project());

        foreach ([
            static fn () => new RenameProjectHandler($world->access())(new RenameProject(Teams::PROJECT, 'x')),
            static fn () => new DeleteProjectHandler($world->access(), $world->projects, $world->invitations, [])(new DeleteProject(Teams::PROJECT)),
            static fn () => new CreateEnvironmentHandler(new EnvironmentAccess($world->access()))(new CreateEnvironment(self::ENV, Teams::PROJECT, 'prod', true)),
        ] as $gesture) {
            try {
                $gesture();
                self::fail('Viewer.');
            } catch (PermissionDenied) {
            }
        }

        self::assertNotNull($world->projects->find(ProjectId::fromString(Teams::PROJECT)));
    }

    #[Test]
    public function it_should_manage_environments_within_the_quota(): void
    {
        $world = new World()->as(Teams::MEMBER, TeamRole::Member);
        $team = Teams::withAdmin();
        $team->changeQuotas(QuotaInput::teamQuotas(self::quotas(1)));
        $world->teams->add($team);
        $project = Teams::project();
        $world->projects->add($project);
        $access = new EnvironmentAccess($world->access());

        new CreateEnvironmentHandler($access)(new CreateEnvironment(self::ENV, Teams::PROJECT, 'prod', true));
        new UpdateEnvironmentHandler($access)(new UpdateEnvironment(self::ENV, Teams::PROJECT, 'production', false));
        self::assertSame('production', $project->environment(EnvironmentId::fromString(self::ENV))->name());
        self::assertFalse($project->environment(EnvironmentId::fromString(self::ENV))->isProduction());

        try {
            new CreateEnvironmentHandler($access)(new CreateEnvironment(Uuid::v7()->toRfc4122(), Teams::PROJECT, 'dev', false));
            self::fail('Quota.');
        } catch (QuotaExceeded $exceeded) {
            self::assertSame(QuotaName::MaxEnvironmentsPerProject, $exceeded->quota);
        }

        $probe = $this->probe(environment: true);
        try {
            new DeleteEnvironmentHandler($access, [$probe])(new DeleteEnvironment(self::ENV, Teams::PROJECT));
            self::fail('Content.');
        } catch (EnvironmentNotEmpty) {
            self::assertSame([self::ENV], $probe->asked);
        }

        new DeleteEnvironmentHandler($access, [$this->probe()])(new DeleteEnvironment(self::ENV, Teams::PROJECT));
        self::assertSame(0, $project->environmentCount());

        foreach ([
            static fn () => new UpdateEnvironmentHandler($access)(new UpdateEnvironment(self::ENV, Teams::PROJECT, 'x', false)),
            static fn () => new DeleteEnvironmentHandler($access, [])(new DeleteEnvironment(self::ENV, Teams::PROJECT)),
        ] as $gesture) {
            try {
                $gesture();
                self::fail('Unknown environment.');
            } catch (ResourceNotFound) {
            }
        }
    }

    private function deleteTeam(World $world): DeleteTeamHandler
    {
        return new DeleteTeamHandler($world->access(), $world->teams, $world->projects, $world->invitations);
    }

    private function createTeam(World $world, FixedAccounts $accounts): CreateTeamHandler
    {
        return new CreateTeamHandler($world->access(), $world->teams, $world->settings, $world->grants, $accounts, new FixedSlugs(), $world->clock);
    }

    private function probe(bool $project = false, bool $environment = false): RecordingContentProbe
    {
        return new RecordingContentProbe($project, $environment);
    }
}
