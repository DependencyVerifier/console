<?php

declare(strict_types=1);

namespace Tests\Organization\Unit\Application;

use Organization\Application\Command\Invitation\Handler\AcceptInvitationHandler;
use Organization\Application\Command\Invitation\Handler\InvitationAccess;
use Organization\Application\Command\Invitation\Handler\InviteMemberHandler;
use Organization\Application\Command\Invitation\Handler\ResendInvitationHandler;
use Organization\Application\Command\Invitation\Handler\RevokeInvitationHandler;
use Organization\Application\Command\Invitation\InviteMember;
use Organization\Application\Command\Invitation\ResendInvitation;
use Organization\Application\Command\Invitation\RevokeInvitation;
use Organization\Application\Command\QuotaInput;
use Organization\Domain\Event\InvitationReissued;
use Organization\Domain\Model\GrantChange;
use Organization\Domain\Model\Invitation;
use Organization\Domain\Model\InvitationAlreadyPending;
use Organization\Domain\Model\InvitationEmailMismatch;
use Organization\Domain\Model\InvitationId;
use Organization\Domain\Model\InvitationNotPending;
use Organization\Domain\Model\InvitationTarget;
use Organization\Domain\Model\ProjectId;
use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\QuotaExceeded;
use Organization\Domain\Model\QuotaName;
use Organization\Domain\Model\RoleNotAssignable;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Contract\Identity\AccountView;
use Shared\Application\Contract\Identity\FindAccounts;
use Shared\Application\Contract\Organization\AcceptInvitation;
use Shared\Application\Security\Throttled;
use Shared\Application\Security\ThrottleName;
use Symfony\Component\Uid\Uuid;
use Tests\Identity\Support\FixedActor;
use Tests\Identity\Support\FixedTokens;
use Tests\Identity\Support\PrefixTokenHasher;
use Tests\Identity\Support\ScriptedThrottle;
use Tests\Organization\Support\FixedQueries;
use Tests\Organization\Support\Teams;
use Tests\Organization\Support\World;

final class InvitationHandlersTest extends TestCase
{
    private const string INVITATION = '019a6b2c-5f00-7a11-8b22-3c44d55e6f01';

    #[Test]
    public function it_should_invite_to_a_team_role_the_actor_may_grant(): void
    {
        $world = $this->world();
        $this->invite($world)(new InviteMember(self::INVITATION, Teams::TEAM, null, 'Jane@example.com', 'team_member'));

        $invitation = $world->invitations->find(InvitationId::fromString(self::INVITATION));
        self::assertNotNull($invitation);
        self::assertSame('jane@example.com', $invitation->email());
        self::assertSame(TeamRole::Member, $invitation->target()->teamRole());

        foreach (['team_overlord', 'project_viewer'] as $role) {
            try {
                $this->invite($world)(new InviteMember(Uuid::v7()->toRfc4122(), Teams::TEAM, null, 'x@example.com', $role));
                self::fail($role);
            } catch (RoleNotAssignable) {
            }
        }
    }

    #[Test]
    public function it_should_keep_one_pending_invitation_per_email_and_target(): void
    {
        $world = $this->world();
        $this->invite($world)(new InviteMember(self::INVITATION, Teams::TEAM, null, 'jane@example.com', 'team_member'));

        try {
            $this->invite($world)(new InviteMember(Uuid::v7()->toRfc4122(), Teams::TEAM, null, 'jane@example.com', 'team_viewer'));
            self::fail('Already pending.');
        } catch (InvitationAlreadyPending) {
        }

        $world->clock->modify('+8 days');
        $replacement = Uuid::v7()->toRfc4122();
        $this->invite($world)(new InviteMember($replacement, Teams::TEAM, null, 'jane@example.com', 'team_viewer'));
        self::assertTrue($world->invitations->find(InvitationId::fromString(self::INVITATION))?->isRevoked(), 'The expired one is revoked first.');
        self::assertNotNull($world->invitations->find(InvitationId::fromString($replacement)));
    }

    #[Test]
    public function it_should_count_members_and_pending_invitations_in_the_quota(): void
    {
        $world = $this->world();
        $team = $world->teams->find(TeamId::fromString(Teams::TEAM));
        self::assertNotNull($team);
        $team->changeQuotas(QuotaInput::teamQuotas([...array_map(static fn (): int => 9, array_flip(array_map(static fn (QuotaName $n): string => $n->value, QuotaName::cases()))), QuotaName::MaxMembers->value => 3]));
        $world->invitations->pendingOfTeam = 1;
        $this->invite($world)(new InviteMember(self::INVITATION, Teams::TEAM, null, 'jane@example.com', 'team_member'));

        $world->invitations->pendingOfTeam = 2;
        $this->expectException(QuotaExceeded::class);
        $this->invite($world)(new InviteMember(Uuid::v7()->toRfc4122(), Teams::TEAM, null, 'john@example.com', 'team_member'));
    }

    #[Test]
    public function it_should_invite_to_a_project_of_the_team_only(): void
    {
        $world = $this->world();
        $world->projects->add(Teams::project());
        $world->projects->add(Teams::project(Teams::OTHER_TEAM, Teams::OTHER_TEAM));
        $this->invite($world)(new InviteMember(self::INVITATION, Teams::TEAM, Teams::PROJECT, 'jane@example.com', 'project_admin'));

        self::assertSame(ProjectRole::Admin, $world->invitations->find(InvitationId::fromString(self::INVITATION))?->target()->projectRole());

        $world->asSuperAdmin();
        foreach ([[Teams::OTHER_TEAM, ResourceNotFound::class], [Teams::PROJECT, RoleNotAssignable::class]] as [$projectId, $refusal]) {
            try {
                $this->invite($world)(new InviteMember(Uuid::v7()->toRfc4122(), Teams::TEAM, $projectId, 'x@example.com', Teams::PROJECT === $projectId ? 'team_member' : 'project_viewer'));
                self::fail($refusal);
            } catch (ResourceNotFound|RoleNotAssignable $refused) {
                self::assertSame($refusal, $refused::class);
            }
        }
    }

    #[Test]
    public function it_should_require_the_members_permission(): void
    {
        $world = $this->world()->as(Teams::MEMBER, TeamRole::Member);
        $world->projects->add(Teams::project());
        foreach ([null, Teams::PROJECT] as $projectId) {
            try {
                $this->invite($world)(new InviteMember(Uuid::v7()->toRfc4122(), Teams::TEAM, $projectId, 'x@example.com', null === $projectId ? 'team_viewer' : 'project_viewer'));
                self::fail('Denied.');
            } catch (PermissionDenied) {
            }
        }

        self::assertSame([], $world->invitations->invitations);
    }

    #[Test]
    public function it_should_resend_within_the_limits_after_the_permission_check(): void
    {
        $world = $this->world();
        $invitation = $this->pending($world);
        $invitation->releaseEvents();
        $throttle = new ScriptedThrottle();
        $world->clock->modify('+1 day');

        new ResendInvitationHandler(new InvitationAccess($world->access(), $world->invitations), $throttle, new FixedTokens(), new PrefixTokenHasher(), $world->clock)(new ResendInvitation(self::INVITATION));

        self::assertEquals($world->clock->now(), $invitation->issuedAt());
        self::assertEquals([new InvitationReissued(self::INVITATION, 'jane@example.com', 'url-token')], $invitation->releaseEvents());
        self::assertSame(['consume invitation_resend invitation:'.self::INVITATION, 'consume invitation_resend_team team:'.Teams::TEAM], $throttle->calls);

        foreach ([ThrottleName::InvitationResend, ThrottleName::InvitationResendTeam] as $name) {
            try {
                new ResendInvitationHandler(new InvitationAccess($world->access(), $world->invitations), new ScriptedThrottle()->refuse($name), new FixedTokens(), new PrefixTokenHasher(), $world->clock)(new ResendInvitation(self::INVITATION));
                self::fail($name->value);
            } catch (Throttled) {
            }
        }

        $denied = new ScriptedThrottle();
        $world->as(Teams::MEMBER, TeamRole::Member);
        try {
            new ResendInvitationHandler(new InvitationAccess($world->access(), $world->invitations), $denied, new FixedTokens(), new PrefixTokenHasher(), $world->clock)(new ResendInvitation(self::INVITATION));
            self::fail('Denied.');
        } catch (PermissionDenied) {
            self::assertSame([], $denied->calls, 'The limiter is never consumed by an unauthorized actor.');
        }
    }

    #[Test]
    public function it_should_revoke_an_invitation_of_a_visible_owner_only(): void
    {
        $world = $this->world();
        $invitation = $this->pending($world);
        new RevokeInvitationHandler(new InvitationAccess($world->access(), $world->invitations))(new RevokeInvitation(self::INVITATION));
        self::assertTrue($invitation->isRevoked());

        $this->expectException(ResourceNotFound::class);
        new RevokeInvitationHandler(new InvitationAccess($world->access(), $world->invitations))(new RevokeInvitation(Uuid::v7()->toRfc4122()));
    }

    #[Test]
    public function it_should_check_project_invitations_on_the_project(): void
    {
        $world = $this->world()->as(Teams::GUEST, TeamRole::Guest, ProjectRole::Admin);
        $world->projects->add(Teams::project());
        $invitation = Invitation::issue(InvitationId::fromString(self::INVITATION), 'jane@example.com', InvitationTarget::project(TeamId::fromString(Teams::TEAM), ProjectId::fromString(Teams::PROJECT), ProjectRole::Viewer), Uuid::fromString(Teams::ADMIN), 't', 'h', $world->clock->now());
        $world->invitations->add($invitation);

        new RevokeInvitationHandler(new InvitationAccess($world->access(), $world->invitations))(new RevokeInvitation(self::INVITATION));
        self::assertTrue($invitation->isRevoked());
    }

    #[Test]
    public function it_should_accept_a_team_invitation_once_with_its_grant(): void
    {
        $world = $this->world();
        $this->pending($world);
        $world->actor = FixedActor::user(Teams::MEMBER);
        $accept = $this->accept($world);

        $accept(new AcceptInvitation(self::INVITATION));
        $team = $world->teams->find(TeamId::fromString(Teams::TEAM));
        self::assertSame(TeamRole::Member, $team?->membershipOf(Uuid::fromString(Teams::MEMBER))->role());
        self::assertCount(1, $world->grants->grants);
        self::assertSame(GrantChange::RoleAssigned, $world->grants->grants[0]->change());
        self::assertSame('team_member', $world->grants->grants[0]->value());

        $this->expectException(InvitationNotPending::class);
        $accept(new AcceptInvitation(self::INVITATION));
    }

    #[Test]
    public function it_should_add_the_team_guest_for_a_project_invitation(): void
    {
        $world = $this->world();
        $world->projects->add(Teams::project());
        $world->invitations->add(Invitation::issue(InvitationId::fromString(self::INVITATION), 'jane@example.com', InvitationTarget::project(TeamId::fromString(Teams::TEAM), ProjectId::fromString(Teams::PROJECT), ProjectRole::Viewer), Uuid::fromString(Teams::ADMIN), 't', 'h', $world->clock->now()));
        $world->actor = FixedActor::user(Teams::MEMBER);

        $this->accept($world)(new AcceptInvitation(self::INVITATION));

        self::assertSame(TeamRole::Guest, $world->teams->find(TeamId::fromString(Teams::TEAM))?->membershipOf(Uuid::fromString(Teams::MEMBER))->role());
        self::assertSame(ProjectRole::Viewer, $world->projects->find(ProjectId::fromString(Teams::PROJECT))?->membershipOf(Uuid::fromString(Teams::MEMBER))->role());
        self::assertSame(['team_guest', 'project_viewer'], array_map(static fn ($g): string => $g->value(), $world->grants->grants));
    }

    #[Test]
    public function it_should_be_idempotent_for_an_existing_member_and_check_the_quota_again(): void
    {
        $world = $this->world();
        $this->pending($world);
        $world->actor = FixedActor::user(Teams::ADMIN);
        $world->teams->find(TeamId::fromString(Teams::TEAM));
        $this->accept($world, 'jane@example.com', Teams::ADMIN)(new AcceptInvitation(self::INVITATION));
        self::assertSame([], $world->grants->grants, 'Already a member: only marked accepted.');
        self::assertTrue($world->invitations->find(InvitationId::fromString(self::INVITATION))?->isAccepted());

        $full = $this->world();
        $full->teams->find(TeamId::fromString(Teams::TEAM))?->changeQuotas(QuotaInput::teamQuotas([...array_map(static fn (): int => 9, array_flip(array_map(static fn (QuotaName $n): string => $n->value, QuotaName::cases()))), QuotaName::MaxMembers->value => 1]));
        $this->pending($full);
        $full->invitations->pendingOfTeam = 1;
        $full->actor = FixedActor::user(Teams::MEMBER);
        $this->expectException(QuotaExceeded::class);
        $this->accept($full)(new AcceptInvitation(self::INVITATION));
    }

    #[Test]
    public function it_should_refuse_another_email_or_a_missing_invitation(): void
    {
        $world = $this->world();
        $this->pending($world);
        $world->actor = FixedActor::user(Teams::MEMBER);
        try {
            $this->accept($world, 'john@example.com')(new AcceptInvitation(self::INVITATION));
            self::fail('Other email.');
        } catch (InvitationEmailMismatch) {
        }

        foreach ([Uuid::v7()->toRfc4122()] as $unknown) {
            try {
                $this->accept($world)(new AcceptInvitation($unknown));
                self::fail('Unknown.');
            } catch (InvitationNotPending) {
            }
        }

        $this->expectException(InvitationNotPending::class);
        $this->accept($world, null)(new AcceptInvitation(self::INVITATION));
    }

    private function world(): World
    {
        $world = new World()->as(Teams::ADMIN, TeamRole::Admin);
        $world->teams->add(Teams::withAdmin());

        return $world;
    }

    private function invite(World $world): InviteMemberHandler
    {
        return new InviteMemberHandler($world->access(), $world->roles(), $world->invitations, new FixedTokens(), new PrefixTokenHasher(), $world->clock);
    }

    private function pending(World $world): Invitation
    {
        $invitation = Invitation::issue(InvitationId::fromString(self::INVITATION), 'jane@example.com', InvitationTarget::team(TeamId::fromString(Teams::TEAM), TeamRole::Member), Uuid::fromString(Teams::ADMIN), 't', 'h', $world->clock->now());
        $world->invitations->add($invitation);

        return $invitation;
    }

    private function accept(World $world, ?string $email = 'jane@example.com', string $userId = Teams::MEMBER): AcceptInvitationHandler
    {
        $queries = new FixedQueries([FindAccounts::class => static fn (): array => null === $email ? [] : [$userId => new AccountView($userId, $email, true)]]);

        return new AcceptInvitationHandler($world->actor, $queries, $world->invitations, $world->teams, $world->projects, $world->grants, $world->clock);
    }
}
