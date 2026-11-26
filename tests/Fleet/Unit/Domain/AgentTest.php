<?php

declare(strict_types=1);

namespace Tests\Fleet\Unit\Domain;

use Fleet\Domain\Event\AgentApproved;
use Fleet\Domain\Event\AgentEnrolled;
use Fleet\Domain\Event\AgentRevoked;
use Fleet\Domain\Model\Agent;
use Fleet\Domain\Model\AgentConfig;
use Fleet\Domain\Model\AgentId;
use Fleet\Domain\Model\AgentNotPending;
use Fleet\Domain\Model\AgentPublicKey;
use Fleet\Domain\Model\AgentStatus;
use Fleet\Domain\Model\HostId;
use Fleet\Domain\Model\InvalidEnrollmentPayload;
use Fleet\Domain\Model\RevokedAgent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Tests\Fleet\Support\AssertsRefusals;
use Tests\Fleet\Support\Fleet;

final class AgentTest extends TestCase
{
    use AssertsRefusals;

    #[Test]
    public function it_should_enroll_a_pending_agent_with_a_single_token(): void
    {
        $token = Fleet::single();
        $agent = Agent::enroll(AgentId::fromString(Fleet::AGENT), $token, Fleet::host(), AgentPublicKey::fromText(Fleet::KEY), Fleet::runtime(), Fleet::now());

        self::assertSame([Fleet::AGENT, Fleet::TEAM, Fleet::HOST, Fleet::TOKEN, Fleet::FINGERPRINT, AgentStatus::Pending], [
            $agent->id()->toString(), $agent->teamId()->toRfc4122(), $agent->hostId()->toString(), $agent->enrollmentTokenId()->toString(), $agent->fingerprint(), $agent->status(),
        ]);
        self::assertSame(Fleet::KEY, $agent->publicKey()->text());
        self::assertEquals(Fleet::runtime(), $agent->runtime());
        self::assertEquals(Fleet::now(), $agent->enrolledAt());
        self::assertEquals(Fleet::now(), $agent->lastSeenAt());
        self::assertNull($agent->approvedAt());
        self::assertNull($agent->approvedBy());
        self::assertNull($agent->revokedAt());
        self::assertFalse($agent->isApproved());
        self::assertSame(0, $agent->appliedConfigVersion());
        self::assertNull($agent->desiredConfig());
        self::assertFalse($agent->lagsBehindConfig());
        self::assertEquals([new AgentEnrolled(Fleet::AGENT, Fleet::TEAM, true, Fleet::facts()->hostname)], $agent->releaseEvents(), 'Waiting for an approval.');
    }

    #[Test]
    public function it_should_enroll_an_approved_agent_with_an_approved_reusable_token_only(): void
    {
        $token = Fleet::reusable();
        self::assertSame(AgentStatus::Pending, Fleet::agent($token)->status(), 'Not approved yet: the agent waits.');

        $token->approve(Uuid::fromString(Fleet::ADMIN), Fleet::now());
        $agent = Fleet::agent($token);
        self::assertSame(AgentStatus::Approved, $agent->status());
        self::assertEquals(Fleet::now(), $agent->approvedAt());
        self::assertNull($agent->approvedBy(), 'Approved by its token, not by a human.');
        $enrolled = Agent::enroll(AgentId::fromString(Fleet::AGENT), $token, Fleet::host(), AgentPublicKey::fromText(Fleet::KEY), Fleet::runtime(), Fleet::now());
        self::assertEquals([new AgentEnrolled(Fleet::AGENT, Fleet::TEAM, false, Fleet::facts()->hostname)], $enrolled->releaseEvents(), 'Nobody to ask.');
    }

    #[Test]
    public function it_should_approve_a_pending_agent_once(): void
    {
        $agent = Fleet::agent();
        $agent->approve(Uuid::fromString(Fleet::ADMIN), Fleet::now()->modify('+1 minute'));

        self::assertTrue($agent->isApproved());
        self::assertEquals(Fleet::now()->modify('+1 minute'), $agent->approvedAt());
        self::assertSame(Fleet::ADMIN, $agent->approvedBy()?->toRfc4122());
        self::assertEquals([new AgentApproved(Fleet::AGENT, Fleet::TEAM)], $agent->releaseEvents());
        $this->assertRefused(AgentNotPending::class, 'agent_not_pending', static fn () => $agent->approve(Uuid::fromString(Fleet::ADMIN), Fleet::now()));
    }

    #[Test]
    public function it_should_revoke_for_good(): void
    {
        $agent = Fleet::agent();
        $agent->revoke(Fleet::now()->modify('+1 minute'));
        $agent->revoke(Fleet::now()->modify('+2 minutes'));

        self::assertSame(AgentStatus::Revoked, $agent->status());
        self::assertEquals(Fleet::now()->modify('+1 minute'), $agent->revokedAt());
        self::assertEquals([new AgentRevoked(Fleet::AGENT, Fleet::TEAM)], $agent->releaseEvents(), 'Idempotent: one event.');
        $this->assertRefused(AgentNotPending::class, 'agent_not_pending', static fn () => $agent->approve(Uuid::fromString(Fleet::ADMIN), Fleet::now()));
        $this->assertRefused(RevokedAgent::class, 'agent_revoked', static fn () => $agent->seen('0.2.0', 0, Fleet::now()));
        $agent->reactivate();
        self::assertSame(AgentStatus::Revoked, $agent->status(), 'Never brought back.');
    }

    #[Test]
    public function it_should_report_for_its_host_while_approved_only(): void
    {
        $pending = Fleet::agent();
        self::assertFalse($pending->reportsFor(HostId::fromString(Fleet::HOST)), 'Not approved yet.');

        $agent = Fleet::approved();
        self::assertTrue($agent->reportsFor(HostId::fromString(Fleet::HOST)));
        self::assertFalse($agent->reportsFor(HostId::fromString('019a6b2c-8f00-7a11-8b22-3c44d55e6f99')), 'Another host.');
        $agent->revoke(Fleet::now()->modify('+1 minute'));
        self::assertFalse($agent->reportsFor(HostId::fromString(Fleet::HOST)), 'Revoked while its inventory waited.');
    }

    #[Test]
    public function it_should_record_each_valid_request(): void
    {
        $agent = Fleet::approved();
        $later = Fleet::now()->modify('+5 minutes');
        $agent->seen('0.2.0', 3, $later);

        self::assertSame('0.2.0', $agent->runtime()->version);
        self::assertSame(3, $agent->appliedConfigVersion());
        self::assertEquals($later, $agent->lastSeenAt());
        $agent->seen('0.2.0', -4, $later);
        self::assertSame(0, $agent->appliedConfigVersion());
        $this->assertRefused(InvalidEnrollmentPayload::class, 'invalid_payload', static fn () => $agent->seen('latest', 0, $later));
    }

    #[Test]
    public function it_should_become_inactive_after_7_silent_days_and_come_back_on_request(): void
    {
        $agent = Fleet::approved();
        self::assertFalse($agent->markInactiveIfSilent(Fleet::now()->modify('+7 days -1 second')));
        self::assertSame(AgentStatus::Approved, $agent->status());

        self::assertTrue($agent->markInactiveIfSilent(Fleet::now()->modify('+7 days')));
        self::assertTrue($agent->isInactive());
        self::assertFalse($agent->status()->countsInQuota());
        self::assertFalse($agent->markInactiveIfSilent(Fleet::now()->modify('+8 days')), 'Already inactive.');

        $agent->seen('0.1.0', 0, Fleet::now()->modify('+9 days'));
        $agent->reactivate();
        self::assertTrue($agent->isApproved());
        self::assertFalse($agent->isInactive());

        $pending = Fleet::agent();
        self::assertFalse($pending->markInactiveIfSilent(Fleet::now()->modify('+30 days')), 'A pending agent stays pending.');
        $pending->reactivate();
        self::assertSame(AgentStatus::Pending, $pending->status());
    }

    #[Test]
    public function it_should_count_pending_and_approved_agents_in_the_quota(): void
    {
        self::assertSame([true, true, false, false], array_map(static fn (AgentStatus $status): bool => $status->countsInQuota(), AgentStatus::cases()));
    }

    #[Test]
    public function it_should_version_each_desired_configuration(): void
    {
        $agent = Fleet::approved();
        $first = $agent->configure(new AgentConfig(360, ['/srv']));
        self::assertSame(1, $first->version);
        self::assertEquals($first, $agent->desiredConfig());
        self::assertTrue($agent->lagsBehindConfig());

        $agent->seen('0.1.0', 1, Fleet::now());
        self::assertFalse($agent->lagsBehindConfig());
        self::assertSame(2, $agent->configure(new AgentConfig(60))->version);

        $agent->seen('0.1.0', 7, Fleet::now());
        self::assertSame(8, $agent->configure(new AgentConfig(60))->version, 'Always above what the agent applied.');
        self::assertTrue($agent->lagsBehindConfig(), 'Desired 8, applied 7.');
        $agent->seen('0.1.0', 9, Fleet::now());
        self::assertFalse($agent->lagsBehindConfig(), 'Applied beyond the desired one (restored volume): nothing to resend.');
    }
}
