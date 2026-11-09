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
use Organization\Application\Command\Team\Handler\CreateTeamHandler;
use Organization\Application\Command\Team\Handler\RenameTeamHandler;
use Organization\Application\Command\Team\Handler\UpdateTeamQuotasHandler;
use Organization\Application\Command\Team\RenameTeam;
use Organization\Application\Command\Team\UpdateTeamQuotas;
use Organization\Domain\Authorization\TeamGrant;
use Organization\Domain\Event\InvitationIssued;
use Organization\Domain\Model\EnvironmentId;
use Organization\Domain\Model\EnvironmentNotEmpty;
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
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;
use Tests\Identity\Support\FixedTokens;
use Tests\Identity\Support\PrefixTokenHasher;
use Tests\Organization\Support\FixedPermissions;
use Tests\Organization\Support\FixedSlugs;
use Tests\Organization\Support\RecordingContentProbe;
use Tests\Organization\Support\Teams;
use Tests\Organization\Support\World;

final class TeamAndProjectHandlersTest extends TestCase
{
    private const string ENV = '019a6b2c-4f00-7a11-8b22-3c44d55e6f01';
    private const string INVITATION = '019a6b2c-5f00-7a11-8b22-3c44d55e6f01';

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
    public function it_should_create_a_team_with_the_defaults_and_invite_its_first_admin(): void
    {
        $world = new World()->asSuperAdmin();
        $world->settings->settings->changeDefaultQuotas(QuotaInput::teamQuotas(self::quotas(7)));
        $world->teams->add(Teams::team(Teams::OTHER_TEAM));

        $this->createTeam($world)(new CreateTeam(Teams::TEAM, 'Platform', 'Jane@Example.com', self::INVITATION));

        $team = $world->teams->find(TeamId::fromString(Teams::TEAM));
        self::assertNotNull($team);
        self::assertSame('platform-2', $team->slug(), 'Unique slug.');
        self::assertSame(7, $team->quotas()->get(QuotaName::MaxProjects)->limit());
        $invitation = $world->invitations->find(InvitationId::fromString(self::INVITATION));
        self::assertNotNull($invitation);
        self::assertSame(TeamRole::Admin, $invitation->target()->teamRole());
        self::assertTrue($invitation->invitedBy()->equals(Uuid::fromString(Teams::ADMIN)));
        self::assertEquals([new InvitationIssued(self::INVITATION, 'jane@example.com', 'url-token')], $invitation->releaseEvents());
    }

    #[Test]
    public function it_should_refuse_a_team_without_member_quota_or_permission(): void
    {
        $world = new World()->asSuperAdmin();
        $world->settings->settings->changeDefaultQuotas(QuotaInput::teamQuotas([...self::quotas(5), QuotaName::MaxMembers->value => 0]));
        try {
            $this->createTeam($world)(new CreateTeam(Teams::TEAM, 'Platform', 'jane@example.com', self::INVITATION));
            self::fail('No member allowed.');
        } catch (QuotaExceeded) {
            self::assertSame([], $world->teams->teams);
        }

        $world->settings->settings->changeDefaultQuotas(QuotaInput::teamQuotas([...self::quotas(5), QuotaName::MaxMembers->value => 1]));
        $this->createTeam($world)(new CreateTeam(Teams::TEAM, 'Platform', 'jane@example.com', self::INVITATION));
        self::assertNotNull($world->teams->find(TeamId::fromString(Teams::TEAM)), 'Room for exactly the invited first admin.');

        $this->expectException(PermissionDenied::class);
        $this->createTeam(new World()->as(Teams::ADMIN, TeamRole::Admin))(new CreateTeam(Teams::TEAM, 'Platform', 'jane@example.com', self::INVITATION));
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
            new UpdateTeamQuotasHandler($world->access())(new UpdateTeamQuotas(Teams::TEAM, self::quotas(1)));
            self::fail('Super admin only.');
        } catch (PermissionDenied) {
        }

        $world->asSuperAdmin();
        new UpdateTeamQuotasHandler($world->access())(new UpdateTeamQuotas(Teams::TEAM, self::quotas(1)));
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

    private function createTeam(World $world): CreateTeamHandler
    {
        return new CreateTeamHandler($world->access(), $world->teams, $world->settings, $world->invitations, new FixedSlugs(), new FixedTokens(), new PrefixTokenHasher(), $world->clock);
    }

    private function probe(bool $project = false, bool $environment = false): RecordingContentProbe
    {
        return new RecordingContentProbe($project, $environment);
    }
}
