<?php

declare(strict_types=1);

namespace Tests\Organization\Unit\Domain\Model;

use Organization\Domain\Event\InvitationIssued;
use Organization\Domain\Event\InvitationReissued;
use Organization\Domain\Model\InvalidInvitationEmail;
use Organization\Domain\Model\Invitation;
use Organization\Domain\Model\InvitationEmailMismatch;
use Organization\Domain\Model\InvitationId;
use Organization\Domain\Model\InvitationNotPending;
use Organization\Domain\Model\InvitationTarget;
use Organization\Domain\Model\ProjectId;
use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Organization\Support\Teams;

use function Symfony\Component\String\u;

final class InvitationTest extends TestCase
{
    private const string ID = '019a6b2c-5f00-7a11-8b22-3c44d55e6f01';

    #[Test]
    public function it_should_issue_a_pending_invitation_for_seven_days(): void
    {
        $invitation = $this->invitation();

        self::assertSame(self::ID, $invitation->id()->toString());
        self::assertSame('jane@example.com', $invitation->email());
        self::assertSame('team:'.Teams::TEAM, $invitation->target()->key());
        self::assertTrue($invitation->invitedBy()->equals(Teams::uuid(Teams::ADMIN)));
        self::assertEquals(Teams::now(), $invitation->issuedAt());
        self::assertEquals(Teams::now()->modify('+7 days'), $invitation->expiresAt());
        self::assertTrue($invitation->isPendingAt(Teams::now()->modify('+7 days -1 second')));
        self::assertFalse($invitation->isPendingAt(Teams::now()->modify('+7 days')));
        self::assertTrue($invitation->isExpiredAt(Teams::now()->modify('+7 days')));
        self::assertFalse($invitation->isAccepted());
        self::assertFalse($invitation->isRevoked());
        self::assertEquals([new InvitationIssued(self::ID, 'jane@example.com', 'plain')], $invitation->releaseEvents());
    }

    #[Test]
    public function it_should_refuse_an_invalid_or_too_long_email(): void
    {
        foreach (['not an email', u('a')->repeat(245)->toString().'@example.com'] as $email) {
            try {
                Invitation::issue(InvitationId::fromString(self::ID), $email, InvitationTarget::team(TeamId::fromString(Teams::TEAM), TeamRole::Member), Teams::uuid(Teams::ADMIN), 'plain', 'hash', Teams::now());
                self::fail($email);
            } catch (InvalidInvitationEmail $refused) {
                self::assertSame('invalid_email_address', $refused->ruleKey());
            }
        }

        $longest = 'a@'.u('a')->repeat(63)->toString().'.'.u('b')->repeat(63)->toString().'.'.u('c')->repeat(63)->toString().'.'.u('d')->repeat(56)->toString().'.com';
        self::assertSame(254, u($longest)->length());
        self::assertSame($longest, Invitation::normalize($longest), '254 characters is the RFC 5321 limit, still accepted.');
    }

    #[Test]
    public function it_should_accept_once_for_the_invited_email_only(): void
    {
        $invitation = $this->invitation();
        try {
            $invitation->accept('john@example.com', Teams::uuid(Teams::MEMBER), Teams::now());
            self::fail('Other email.');
        } catch (InvitationEmailMismatch) {
        }

        $invitation->accept(' JANE@example.com', Teams::uuid(Teams::MEMBER), Teams::now());
        self::assertTrue($invitation->isAccepted());
        self::assertFalse($invitation->isPendingAt(Teams::now()));
        self::assertTrue($invitation->acceptedBy()->equals(Teams::uuid(Teams::MEMBER)));

        foreach ([static fn () => $invitation->accept('jane@example.com', Teams::uuid(Teams::MEMBER), Teams::now()), $invitation->revoke(...), static fn () => $invitation->reissue('p', 'h', Teams::now())] as $gesture) {
            try {
                $gesture();
                self::fail('Not pending any more.');
            } catch (InvitationNotPending) {
            }
        }
    }

    #[Test]
    public function it_should_refuse_an_expired_or_revoked_invitation(): void
    {
        $expired = $this->invitation();
        try {
            $expired->accept('jane@example.com', Teams::uuid(Teams::MEMBER), Teams::now()->modify('+8 days'));
            self::fail('Expired.');
        } catch (InvitationNotPending) {
        }

        $revoked = $this->invitation();
        $revoked->revoke();
        $revoked->revoke();
        self::assertTrue($revoked->isRevoked());

        $this->expectException(InvitationNotPending::class);
        $revoked->accept('jane@example.com', Teams::uuid(Teams::MEMBER), Teams::now());
    }

    #[Test]
    public function it_should_reissue_a_new_token_and_validity(): void
    {
        $invitation = $this->invitation();
        $invitation->releaseEvents();
        $later = Teams::now()->modify('+6 days');
        $invitation->reissue('plain-2', 'h:plain-2', $later);

        self::assertEquals($later, $invitation->issuedAt());
        self::assertTrue($invitation->isPendingAt(Teams::now()->modify('+12 days')));
        self::assertEquals([new InvitationReissued(self::ID, 'jane@example.com', 'plain-2')], $invitation->releaseEvents());

        $this->expectException(\LogicException::class);
        $invitation->acceptedBy();
    }

    #[Test]
    public function it_should_describe_its_target(): void
    {
        $team = InvitationTarget::team(TeamId::fromString(Teams::TEAM), TeamRole::Viewer);
        $project = InvitationTarget::project(TeamId::fromString(Teams::TEAM), ProjectId::fromString(Teams::PROJECT), ProjectRole::Member);

        self::assertFalse($team->isProject());
        self::assertSame(TeamRole::Viewer, $team->teamRole());
        self::assertSame('team_viewer', $team->roleValue());
        self::assertSame('team:'.Teams::TEAM, $team->key());
        self::assertSame(Teams::TEAM, $project->teamId()->toString());
        self::assertTrue($project->isProject());
        self::assertSame(Teams::PROJECT, $project->projectId()->toString());
        self::assertSame(ProjectRole::Member, $project->projectRole());
        self::assertSame('project:'.Teams::PROJECT, $project->key());

        foreach ([$team->projectId(...), $team->projectRole(...), $project->teamRole(...)] as $gesture) {
            try {
                $gesture();
                self::fail('Wrong kind.');
            } catch (\LogicException) {
            }
        }
    }

    #[Test]
    public function it_should_never_serialize_an_event_carrying_a_token(): void
    {
        foreach ([new InvitationIssued('i', 'e', 't'), new InvitationReissued('i', 'e', 't')] as $event) {
            try {
                serialize($event);
                self::fail($event::class);
            } catch (\LogicException $refused) {
                self::assertSame('An event carrying a secret is never serialized.', $refused->getMessage());
            }
        }
    }

    private function invitation(): Invitation
    {
        return Invitation::issue(InvitationId::fromString(self::ID), ' Jane@Example.com ', InvitationTarget::team(TeamId::fromString(Teams::TEAM), TeamRole::Member), Teams::uuid(Teams::ADMIN), 'plain', 'h:plain', Teams::now());
    }
}
