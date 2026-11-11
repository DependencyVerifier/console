<?php

declare(strict_types=1);

namespace Tests\Fleet\Unit\Application;

use Fleet\Application\Command\Agent\ApproveAgent;
use Fleet\Application\Command\Agent\Handler\AgentRevocation;
use Fleet\Application\Command\Agent\Handler\ApproveAgentHandler;
use Fleet\Application\Command\Agent\Handler\RevokeAgentHandler;
use Fleet\Application\Command\Agent\RevokeAgent;
use Fleet\Application\Command\Host\DeleteHost;
use Fleet\Application\Command\Host\Handler\DeleteHostHandler;
use Fleet\Application\Command\Token\ApproveEnrollmentToken;
use Fleet\Application\Command\Token\CreateEnrollmentToken;
use Fleet\Application\Command\Token\Handler\ApproveEnrollmentTokenHandler;
use Fleet\Application\Command\Token\Handler\CreateEnrollmentTokenHandler;
use Fleet\Application\Command\Token\Handler\RevokeEnrollmentTokenHandler;
use Fleet\Application\Command\Token\RevokeEnrollmentToken;
use Fleet\Domain\Model\AgentOrder;
use Fleet\Domain\Model\AgentOrderId;
use Fleet\Domain\Model\AgentStatus;
use Fleet\Domain\Model\EnrollmentTokenId;
use Fleet\Domain\Model\EnrollmentTokenKind;
use Fleet\Domain\Model\EnrollmentTokenStatus;
use Fleet\Domain\Model\FleetQuotaReached;
use Fleet\Domain\Model\HostHasAgents;
use Fleet\Domain\Model\InvalidEnrollmentTokenSettings;
use Fleet\Domain\Model\OrderStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Audit\Target;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Contract\Organization\TeamEnvironment;
use Shared\Domain\Authorization\Permission;
use Tests\Fleet\Support\Fleet;
use Tests\Fleet\Support\FleetWorld;
use Tests\Identity\Support\PrefixTokenHasher;
use Tests\Organization\Support\Teams;

final class UiHandlersTest extends TestCase
{
    private const string NEW_TOKEN = '019a6b2c-6f00-7a11-8b22-3c44d55e6f51';

    #[Test]
    public function it_should_create_a_single_token_shown_once_and_stored_hashed(): void
    {
        $world = new FleetWorld()->as('team_member', Permission::HostEnroll);
        $world->environments = [new TeamEnvironment(Fleet::ENVIRONMENT, 'production', Teams::PROJECT, 'Shop')];
        $this->create($world)(new CreateEnrollmentToken(self::NEW_TOKEN, Fleet::TEAM, false, Fleet::ENVIRONMENT, 'dvet_secret'));

        $token = $world->tokens->find(EnrollmentTokenId::fromString(self::NEW_TOKEN));
        self::assertNotNull($token);
        self::assertSame([EnrollmentTokenKind::Single, Fleet::ENVIRONMENT, Fleet::TEAM, Fleet::ADMIN, 1], [$token->kind(), $token->environmentId()?->toRfc4122(), $token->teamId()->toRfc4122(), $token->createdBy()->toRfc4122(), $token->maxUses()]);
        self::assertSame($token, $world->tokens->findByHash('h:dvet_secret'));
        self::assertEquals(Fleet::now()->modify('+1 hour'), $token->expiresAt());
        self::assertSame(['fleet.team:'.Fleet::TEAM], $world->lock->keys);

        $this->create($world)(new CreateEnrollmentToken('019a6b2c-6f00-7a11-8b22-3c44d55e6f52', Fleet::TEAM, false, null, 'dvet_other'));
        self::assertNull($world->tokens->find(EnrollmentTokenId::fromString('019a6b2c-6f00-7a11-8b22-3c44d55e6f52'))?->environmentId());
    }

    #[Test]
    public function it_should_create_a_reusable_token_into_an_environment_of_the_team(): void
    {
        $world = new FleetWorld()->as('team_admin', Permission::HostEnroll);
        $world->environments = [new TeamEnvironment(Fleet::ENVIRONMENT, 'production', Teams::PROJECT, 'Shop')];
        $this->create($world)(new CreateEnrollmentToken(self::NEW_TOKEN, Fleet::TEAM, true, Fleet::ENVIRONMENT, 'dvet_secret', 7, 3));

        $token = $world->tokens->find(EnrollmentTokenId::fromString(self::NEW_TOKEN));
        self::assertNotNull($token);
        self::assertSame([EnrollmentTokenKind::Reusable, EnrollmentTokenStatus::Pending, 3], [$token->kind(), $token->status(), $token->maxUses()]);
        self::assertEquals(Fleet::now()->modify('+7 days'), $token->expiresAt());

        $this->assertRefusal(InvalidEnrollmentTokenSettings::class, fn () => $this->create($world)(new CreateEnrollmentToken('019a6b2c-6f00-7a11-8b22-3c44d55e6f53', Fleet::TEAM, true, null, 'dvet_x', 7, 3)));
        $this->assertRefusal(ResourceNotFound::class, fn () => $this->create($world)(new CreateEnrollmentToken('019a6b2c-6f00-7a11-8b22-3c44d55e6f53', Fleet::TEAM, true, Fleet::TEAM, 'dvet_x', 7, 3)));
        self::assertCount(1, $world->tokens->tokens);
    }

    #[Test]
    public function it_should_refuse_a_token_beyond_the_quotas(): void
    {
        $world = new FleetWorld()->as('team_admin', Permission::HostEnroll);
        $world->environments = [new TeamEnvironment(Fleet::ENVIRONMENT, 'production', Teams::PROJECT, 'Shop')];
        $world->tokens->add(Fleet::reusable());
        $world->limits['max_reusable_enrollment_tokens'] = 1;
        $this->assertRefusal(FleetQuotaReached::class, fn () => $this->create($world)(new CreateEnrollmentToken(self::NEW_TOKEN, Fleet::TEAM, true, Fleet::ENVIRONMENT, 'dvet_x', 7, 3)));
        $this->create($world)(new CreateEnrollmentToken(self::NEW_TOKEN, Fleet::TEAM, false, null, 'dvet_x'));
        self::assertCount(2, $world->tokens->tokens, 'A single token is not a reusable one.');

        $world->agents->add(Fleet::agent());
        $world->limits['max_agents'] = 1;
        $this->assertRefusal(FleetQuotaReached::class, fn () => $this->create($world)(new CreateEnrollmentToken('019a6b2c-6f00-7a11-8b22-3c44d55e6f53', Fleet::TEAM, false, null, 'dvet_y')));
        self::assertCount(2, $world->tokens->tokens);
    }

    #[Test]
    public function it_should_refuse_tokens_to_whom_may_not_enroll(): void
    {
        $guest = new FleetWorld()->as('team_guest', Permission::HostEnroll);
        $this->assertRefusal(PermissionDenied::class, fn () => $this->create($guest)(new CreateEnrollmentToken(self::NEW_TOKEN, Fleet::TEAM, false, null, 'dvet_x')));
        $viewer = new FleetWorld()->as('team_viewer');
        $this->assertRefusal(PermissionDenied::class, fn () => $this->create($viewer)(new CreateEnrollmentToken(self::NEW_TOKEN, Fleet::TEAM, false, null, 'dvet_x')));
        $other = new FleetWorld()->as('team_admin', Permission::HostEnroll);
        $this->assertRefusal(ResourceNotFound::class, fn () => $this->create($other)(new CreateEnrollmentToken(self::NEW_TOKEN, Fleet::OTHER_TEAM, false, null, 'dvet_x')));
        self::assertSame([], $other->lock->keys);

        $command = new CreateEnrollmentToken(self::NEW_TOKEN, Fleet::TEAM, true, null, 'dvet_x');
        self::assertSame('fleet.create_enrollment_token', $command->auditAction()->name);
        self::assertEquals(Target::of('team', Fleet::TEAM), $command->auditTarget());
        self::assertSame(['token_id' => self::NEW_TOKEN, 'kind' => 'reusable'], $command->auditContext()->values);
        self::assertSame(['token_id' => self::NEW_TOKEN, 'kind' => 'single'], new CreateEnrollmentToken(self::NEW_TOKEN, Fleet::TEAM, false, null, 'x')->auditContext()->values);
    }

    #[Test]
    public function it_should_approve_and_revoke_tokens_of_the_real_team(): void
    {
        $world = new FleetWorld()->as('team_admin', Permission::HostApprove, Permission::HostRevoke);
        $token = Fleet::reusable();
        $world->tokens->add($token);
        $first = Fleet::agent($token);
        $second = Fleet::agent($token, '019a6b2c-8f00-7a11-8b22-3c44d55e6f02');
        $other = Fleet::agent(Fleet::single(id: '019a6b2c-6f00-7a11-8b22-3c44d55e6f59'), '019a6b2c-8f00-7a11-8b22-3c44d55e6f03');
        array_map($world->agents->add(...), [$first, $second, $other]);

        new ApproveEnrollmentTokenHandler($world->access(), $world->tokens, $world->actor, $world->clock)(new ApproveEnrollmentToken(Fleet::TOKEN));
        self::assertSame([EnrollmentTokenStatus::Approved, Fleet::ADMIN], [$token->status(), $token->approvedBy()?->toRfc4122()]);

        $revoke = new RevokeEnrollmentTokenHandler($world->access(), $world->tokens, $world->agents, new AgentRevocation($world->orders, $world->clock), $world->clock);
        $revoke(new RevokeEnrollmentToken(Fleet::TOKEN));
        self::assertSame([EnrollmentTokenStatus::Revoked, AgentStatus::Pending], [$token->status(), $first->status()], 'Its agents stay unless asked.');
        $revoke(new RevokeEnrollmentToken(Fleet::TOKEN, true));
        self::assertSame([AgentStatus::Revoked, AgentStatus::Revoked, AgentStatus::Pending], [$first->status(), $second->status(), $other->status()]);

        $this->assertRefusal(ResourceNotFound::class, static fn () => $revoke(new RevokeEnrollmentToken(self::NEW_TOKEN)));
        $this->assertRefusal(ResourceNotFound::class, static fn () => new ApproveEnrollmentTokenHandler($world->access(), $world->tokens, $world->actor, $world->clock)(new ApproveEnrollmentToken(self::NEW_TOKEN)));
        $world->as('team_admin');
        $this->assertRefusal(PermissionDenied::class, static fn () => $revoke(new RevokeEnrollmentToken(Fleet::TOKEN)));
        $this->assertRefusal(PermissionDenied::class, static fn () => new ApproveEnrollmentTokenHandler($world->access(), $world->tokens, $world->actor, $world->clock)(new ApproveEnrollmentToken(Fleet::TOKEN)));
        self::assertSame(['with_agents' => true], new RevokeEnrollmentToken(Fleet::TOKEN, true)->auditContext()->values);
        self::assertEquals(Target::of('enrollment_token', Fleet::TOKEN), new RevokeEnrollmentToken(Fleet::TOKEN)->auditTarget());
        self::assertSame('fleet.revoke_enrollment_token', new RevokeEnrollmentToken(Fleet::TOKEN)->auditAction()->name);
        self::assertEquals(Target::of('enrollment_token', Fleet::TOKEN), new ApproveEnrollmentToken(Fleet::TOKEN)->auditTarget());
    }

    #[Test]
    public function it_should_approve_a_pending_agent_within_the_quota(): void
    {
        $world = new FleetWorld()->as('team_admin', Permission::HostApprove);
        $agent = Fleet::agent();
        $world->agents->add($agent);
        $world->agents->add(Fleet::agent(id: '019a6b2c-8f00-7a11-8b22-3c44d55e6f02'));
        $approve = new ApproveAgentHandler($world->access(), $world->agents, $world->quotas(), $world->actor, $world->clock);

        $world->limits['max_agents'] = 1;
        $this->assertRefusal(FleetQuotaReached::class, static fn () => $approve(new ApproveAgent(Fleet::AGENT)), 'The quota was lowered under the usage.');
        $world->limits['max_agents'] = 2;
        $approve(new ApproveAgent(Fleet::AGENT));
        self::assertSame([AgentStatus::Approved, Fleet::ADMIN], [$agent->status(), $agent->approvedBy()?->toRfc4122()]);
        self::assertSame(['fleet.team:'.Fleet::TEAM, 'fleet.team:'.Fleet::TEAM], $world->lock->keys);

        $this->assertRefusal(ResourceNotFound::class, static fn () => $approve(new ApproveAgent(Fleet::ORDER)));
        $world->as('team_member');
        $this->assertRefusal(PermissionDenied::class, static fn () => $approve(new ApproveAgent('019a6b2c-8f00-7a11-8b22-3c44d55e6f02')));
        self::assertEquals(Target::of('agent', Fleet::AGENT), new ApproveAgent(Fleet::AGENT)->auditTarget());
        self::assertSame(['fleet.approve_agent', []], [new ApproveAgent(Fleet::AGENT)->auditAction()->name, new ApproveAgent(Fleet::AGENT)->auditContext()->values]);
    }

    #[Test]
    public function it_should_revoke_an_agent_and_cancel_its_open_orders(): void
    {
        $world = new FleetWorld()->as('team_admin', Permission::HostRevoke);
        $agent = Fleet::approved();
        $world->agents->add($agent);
        $queued = AgentOrder::scanNow(AgentOrderId::fromString(Fleet::ORDER), $agent, null, Fleet::now());
        $world->orders->add($queued);
        $revoke = new RevokeAgentHandler($world->access(), $world->agents, new AgentRevocation($world->orders, $world->clock));

        $revoke(new RevokeAgent(Fleet::AGENT));
        self::assertSame([AgentStatus::Revoked, OrderStatus::Cancelled], [$agent->status(), $queued->status()]);
        self::assertEquals(Fleet::now(), $agent->revokedAt());
        $this->assertRefusal(ResourceNotFound::class, static fn () => $revoke(new RevokeAgent(Fleet::ORDER)));
        $world->as('team_viewer');
        $this->assertRefusal(PermissionDenied::class, static fn () => $revoke(new RevokeAgent(Fleet::AGENT)));
        self::assertSame('fleet.revoke_agent', new RevokeAgent(Fleet::AGENT)->auditAction()->name);
    }

    #[Test]
    public function it_should_delete_a_host_once_its_agents_are_revoked(): void
    {
        $world = new FleetWorld()->as('team_admin', Permission::HostDelete);
        $world->hosts->add(Fleet::host());
        $agent = Fleet::approved();
        $world->agents->add($agent);
        $world->orders->add(AgentOrder::scanNow(AgentOrderId::fromString(Fleet::ORDER), $agent, null, Fleet::now()));
        $delete = new DeleteHostHandler($world->access(), $world->hosts, $world->agents, $world->orders);

        $this->assertRefusal(HostHasAgents::class, static fn () => $delete(new DeleteHost(Fleet::HOST)));
        self::assertCount(1, $world->hosts->hosts);
        $agent->revoke(Fleet::now());
        $delete(new DeleteHost(Fleet::HOST));
        self::assertNull($world->hosts->find(Fleet::host()->id()));
        self::assertNull($world->agents->find($agent->id()), 'Its revoked agents go with it…');
        self::assertNull($world->orders->find(AgentOrderId::fromString(Fleet::ORDER)), '…and their orders.');

        $this->assertRefusal(ResourceNotFound::class, static fn () => $delete(new DeleteHost(Fleet::HOST)));
        $world->hosts->add(Fleet::host());
        $world->as('team_member');
        $this->assertRefusal(PermissionDenied::class, static fn () => $delete(new DeleteHost(Fleet::HOST)));
        self::assertEquals(Target::of('host', Fleet::HOST), new DeleteHost(Fleet::HOST)->auditTarget());
    }

    private function create(FleetWorld $world): CreateEnrollmentTokenHandler
    {
        return new CreateEnrollmentTokenHandler($world->access(), $world->queries, $world->quotas(), $world->tokens, new PrefixTokenHasher(), $world->actor, $world->clock);
    }

    /**
     * @param class-string<\Throwable> $class
     * @param \Closure(): mixed        $gesture
     */
    private function assertRefusal(string $class, \Closure $gesture, string $message = ''): void
    {
        try {
            $gesture();
            self::fail('Expected '.$class.'. '.$message);
        } catch (\Throwable $refused) {
            self::assertInstanceOf($class, $refused, $message.' '.$refused->getMessage());
        }
    }
}
