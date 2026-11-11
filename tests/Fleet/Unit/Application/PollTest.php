<?php

declare(strict_types=1);

namespace Tests\Fleet\Unit\Application;

use Fleet\Application\Command\Agent\RecordAgentPoll;
use Fleet\Application\Protocol\OrderSigner;
use Fleet\Application\Protocol\Problem;
use Fleet\Application\Protocol\ProtocolRefusal;
use Fleet\Application\Query\Agent\FindEnrolledAgent;
use Fleet\Application\Query\Agent\GetPollAnswer;
use Fleet\Application\Query\Agent\Handler\FindEnrolledAgentHandler;
use Fleet\Application\Query\Agent\Handler\GetPollAnswerHandler;
use Fleet\Application\Query\View\EnrollmentAnswer;
use Fleet\Application\Query\View\PollAnswer;
use Fleet\Application\Quota\FleetQuotaUsage;
use Fleet\Domain\Model\Agent;
use Fleet\Domain\Model\AgentConfig;
use Fleet\Domain\Model\AgentOrder;
use Fleet\Domain\Model\AgentOrderId;
use Fleet\Domain\Model\AgentStatus;
use Fleet\Domain\Model\FleetQuotaReached;
use Fleet\Domain\Model\OrderStatus;
use Fleet\Domain\Model\OrderType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Audit\Target;
use Shared\Application\Contract\Organization\GetTeamQuota;
use Symfony\Component\Uid\Uuid;
use Tests\Fleet\Support\Fleet;
use Tests\Fleet\Support\FleetWorld;

final class PollTest extends TestCase
{
    private const string OTHER_ORDER = '019a6b2c-9f00-7a11-8b22-3c44d55e6f02';

    #[Test]
    public function it_should_record_a_pending_agent_alive_without_any_order(): void
    {
        $world = new FleetWorld();
        $agent = Fleet::agent();
        $world->agents->add($agent);
        $host = Fleet::host();
        $world->hosts->add($host);
        $world->clock->modify('+1 minute');

        $world->poll()($this->poll(version: '0.2.0', configVersion: 4));
        self::assertEquals($world->clock->now(), $agent->lastSeenAt());
        self::assertEquals($world->clock->now(), $host->lastSeenAt());
        self::assertSame(['0.2.0', 4], [$agent->runtime()->version, $agent->appliedConfigVersion()]);
        self::assertEquals(new PollAnswer('pending', [], 60), $this->answer($world));
    }

    #[Test]
    public function it_should_sign_the_queued_orders_and_deliver_them_until_their_result(): void
    {
        $world = new FleetWorld();
        $agent = $this->approved($world);
        $scan = AgentOrder::scanNow(AgentOrderId::fromString(Fleet::ORDER), $agent, null, Fleet::now());
        $world->orders->add($scan);
        $world->poll()($this->poll());

        self::assertSame(OrderStatus::Delivered, $scan->status());
        self::assertEquals(Fleet::now(), $scan->issuedAt());
        $envelope = new OrderSigner($world->signer)->envelope($scan);
        self::assertEquals(new PollAnswer('approved', [$envelope], 300), $this->answer($world));

        $world->clock->modify('+5 minutes');
        $world->poll()($this->poll());
        self::assertEquals(Fleet::now(), $scan->issuedAt(), 'Signed once, delivered again as is.');
        self::assertEquals([$envelope], $this->answer($world)->orders);

        $world->poll()($this->poll([['order_id' => Fleet::ORDER, 'status' => 'failed', 'reason' => 'scan_error', 'finished_at' => '2026-11-09T08:04:00Z']]));
        $finished = $world->orders->find(AgentOrderId::fromString(Fleet::ORDER));
        self::assertNotNull($finished);
        self::assertSame([OrderStatus::Failed, 'scan_error'], [$finished->status(), $finished->reason()]);
        self::assertEquals(new \DateTimeImmutable('2026-11-09 08:04:00', new \DateTimeZone('UTC')), $finished->finishedAt());
        self::assertSame([], $this->answer($world)->orders);
    }

    #[Test]
    public function it_should_expire_a_delivered_order_without_result_and_never_deliver_it_again(): void
    {
        $world = new FleetWorld();
        $agent = $this->approved($world);
        $scan = AgentOrder::scanNow(AgentOrderId::fromString(Fleet::ORDER), $agent, null, Fleet::now());
        $world->orders->add($scan);
        $world->poll()($this->poll());

        $world->clock->modify('+15 minutes');
        self::assertSame([], $this->answer($world)->orders, 'Past its validity: not sent.');
        $world->clock->modify('+60 seconds');
        $world->poll()($this->poll());
        self::assertSame(OrderStatus::Expired, $scan->status());
    }

    #[Test]
    public function it_should_ignore_results_of_unknown_or_foreign_orders(): void
    {
        $world = new FleetWorld();
        $agent = $this->approved($world);
        $other = Fleet::approvedWithId('019a6b2c-8f00-7a11-8b22-3c44d55e6f02');
        $world->agents->add($other);
        $foreign = AgentOrder::scanNow(AgentOrderId::fromString(self::OTHER_ORDER), $other, null, Fleet::now());
        $foreign->issue(Fleet::now());
        $foreign->deliver('s');
        $world->orders->add($foreign);

        $world->poll()($this->poll([
            ['order_id' => self::OTHER_ORDER, 'status' => 'executed', 'finished_at' => '2026-11-09T08:00:00Z'],
            ['order_id' => '019a6b2c-9f00-7a11-8b22-3c44d55e6f03', 'status' => 'executed', 'finished_at' => '2026-11-09T08:00:00Z'],
        ]));
        self::assertSame(OrderStatus::Delivered, $foreign->status(), 'Another agent cannot report on it.');
        self::assertEquals(Fleet::now(), $agent->lastSeenAt());
    }

    #[Test]
    public function it_should_refuse_malformed_results(): void
    {
        foreach ([
            ['order_id' => 'nope', 'status' => 'executed', 'finished_at' => '2026-11-09T08:00:00Z'],
            ['order_id' => Fleet::ORDER, 'status' => 'done', 'finished_at' => '2026-11-09T08:00:00Z'],
            ['order_id' => Fleet::ORDER, 'status' => 'executed', 'finished_at' => '2026-11-09 08:00:00'],
            ['order_id' => Fleet::ORDER, 'status' => 'executed', 'finished_at' => '2026-11-09T08:00:00+01:00'],
            ['order_id' => Fleet::ORDER, 'status' => 'executed'],
        ] as $result) {
            $world = new FleetWorld();
            $this->approved($world);
            try {
                $world->poll()($this->poll([$result]));
                self::fail('Malformed: '.json_encode($result));
            } catch (ProtocolRefusal $refusal) {
                self::assertSame(Problem::InvalidPayload, $refusal->problem);
            }
        }
    }

    #[Test]
    public function it_should_queue_a_lagging_configuration_once_and_deliver_it_at_once(): void
    {
        $world = new FleetWorld();
        $agent = $this->approved($world);
        $agent->configure(new AgentConfig(360));

        $world->poll()($this->poll(configVersion: 0));
        $sent = $world->orders->lastOf($agent->id(), OrderType::SetConfig);
        self::assertNotNull($sent);
        self::assertSame([OrderStatus::Delivered, ['config_version' => 1, 'scan_interval_minutes' => 360, 'discovery_roots' => [], 'declared_paths' => [], 'exclusions' => []]], [$sent->status(), $sent->payload()]);
        self::assertNull($sent->requestedBy());

        $world->poll()($this->poll(configVersion: 0));
        self::assertCount(1, $world->orders->orders, 'One SET_CONFIG on its way: not queued again.');

        $sent->finish(OrderStatus::Rejected, 'expired', Fleet::now(), Fleet::now());
        $world->poll()($this->poll(configVersion: 0));
        self::assertCount(2, $world->orders->orders, 'Lost: queued again.');

        $world->poll()($this->poll(configVersion: 1));
        self::assertCount(2, $world->orders->orders, 'Applied: nothing more.');
    }

    #[Test]
    public function it_should_deliver_twenty_orders_at_most(): void
    {
        $world = new FleetWorld();
        $agent = $this->approved($world);
        $agent->configure(new AgentConfig(60));
        for ($i = 0; $i < 20; ++$i) {
            $world->orders->add(AgentOrder::scanNow(AgentOrderId::of(Uuid::v7()), $agent, null, Fleet::now()));
        }

        $world->poll()($this->poll());
        self::assertCount(20, $this->answer($world)->orders);
        self::assertSame(OrderStatus::Queued, $world->orders->lastOf($agent->id(), OrderType::SetConfig)?->status(), 'No room left: the lagging configuration waits for the next poll.');
    }

    #[Test]
    public function it_should_bring_back_an_inactive_agent_if_the_quota_allows(): void
    {
        $world = new FleetWorld();
        $agent = $this->approved($world);
        $agent->markInactiveIfSilent(Fleet::now()->modify('+7 days'));
        $world->agents->add(Fleet::agent(id: '019a6b2c-8f00-7a11-8b22-3c44d55e6f02'));
        $world->limits['max_agents'] = 1;

        try {
            $world->poll()($this->poll());
            self::fail('No seat left.');
        } catch (FleetQuotaReached $reached) {
            self::assertSame(['max_agents', 1], [$reached->quota, $reached->limit]);
        }

        self::assertSame(['fleet.team:'.Fleet::TEAM], $world->lock->keys);
        $world->limits['max_agents'] = 2;
        $world->poll()($this->poll());
        self::assertSame(AgentStatus::Approved, $agent->status());
        self::assertEquals(new GetTeamQuota(Fleet::TEAM, 'max_agents'), $world->queries->asked[0]);
    }

    #[Test]
    public function it_should_refuse_an_agent_that_no_longer_exists(): void
    {
        $world = new FleetWorld();
        foreach ([static fn () => $world->poll()(new RecordAgentPoll(Fleet::AGENT, '{"protocol_version":1,"agent_version":"0.1.0","config_version":0}')), fn () => $this->answer($world)] as $gesture) {
            try {
                $gesture();
                self::fail('Unknown agent.');
            } catch (ProtocolRefusal $refusal) {
                self::assertSame(Problem::UnknownAgent, $refusal->problem);
            }
        }

        $command = new RecordAgentPoll(Fleet::AGENT, '{}');
        self::assertSame('fleet.poll', $command->auditAction()->name);
        self::assertEquals(Target::of('agent', Fleet::AGENT), $command->auditTarget());
        self::assertSame([], $command->auditContext()->values);
    }

    #[Test]
    public function it_should_answer_an_enrollment_with_the_live_agent_of_the_key(): void
    {
        $world = new FleetWorld();
        $agent = Fleet::agent();
        $world->agents->add($agent);
        $handler = new FindEnrolledAgentHandler($world->agents, $world->signer);
        $consoleKey = $world->signer->publicKey()->fingerprint();

        self::assertEquals(new EnrollmentAnswer(Fleet::AGENT, 'pending', Fleet::FINGERPRINT, $consoleKey, 60), $handler(new FindEnrolledAgent(Fleet::KEY)));
        $agent->approve(Uuid::v7(), Fleet::now());
        self::assertEquals(new EnrollmentAnswer(Fleet::AGENT, 'approved', Fleet::FINGERPRINT, $consoleKey, 300), $handler(new FindEnrolledAgent(Fleet::KEY)));

        $agent->revoke(Fleet::now());
        try {
            $handler(new FindEnrolledAgent(Fleet::KEY));
            self::fail('Revoked.');
        } catch (ProtocolRefusal $refusal) {
            self::assertSame(Problem::UnknownAgent, $refusal->problem);
        }
    }

    #[Test]
    public function it_should_count_the_fleet_quotas_for_the_gauges(): void
    {
        $world = new FleetWorld();
        $world->agents->add(Fleet::agent());
        $world->agents->add(Fleet::approvedWithId('019a6b2c-8f00-7a11-8b22-3c44d55e6f02'));
        $revoked = Fleet::agent(id: '019a6b2c-8f00-7a11-8b22-3c44d55e6f03');
        $revoked->revoke(Fleet::now());
        $world->agents->add($revoked);
        $world->tokens->add(Fleet::reusable());
        $world->tokens->add(Fleet::single(id: '019a6b2c-6f00-7a11-8b22-3c44d55e6f41'));
        $usage = new FleetQuotaUsage($world->agents, $world->tokens, $world->clock);

        self::assertSame(['max_agents', 'max_reusable_enrollment_tokens'], $usage->quotas());
        self::assertSame([2, 1], [$usage->usage(Fleet::TEAM, 'max_agents'), $usage->usage(Fleet::TEAM, 'max_reusable_enrollment_tokens')]);
        self::assertSame([0, 0], [$usage->usage(Fleet::OTHER_TEAM, 'max_agents'), $usage->usage(Fleet::OTHER_TEAM, 'max_reusable_enrollment_tokens')]);
        $world->clock->modify('+30 days');
        self::assertSame(0, $usage->usage(Fleet::TEAM, 'max_reusable_enrollment_tokens'), 'Expired: not active.');
    }

    #[Test]
    public function it_should_block_a_reusable_token_beyond_its_quota(): void
    {
        $world = new FleetWorld();
        $world->tokens->add(Fleet::reusable());
        $world->limits['max_reusable_enrollment_tokens'] = 2;
        $world->quotas()->assertReusableTokenSeat(Uuid::fromString(Fleet::TEAM));
        $world->limits['max_reusable_enrollment_tokens'] = 1;
        try {
            $world->quotas()->assertReusableTokenSeat(Uuid::fromString(Fleet::TEAM));
            self::fail('Full.');
        } catch (FleetQuotaReached $reached) {
            self::assertSame(['max_reusable_enrollment_tokens', 1, 'quota', 'Quota "max_reusable_enrollment_tokens" of 1 reached.'], [$reached->quota, $reached->limit, $reached->ruleKey(), $reached->getMessage()]);
        }

        unset($world->limits['max_reusable_enrollment_tokens']);
        $world->quotas()->assertReusableTokenSeat(Uuid::fromString(Fleet::TEAM));
        self::assertNull($world->quotas()->limit(Uuid::fromString(Fleet::TEAM), 'max_agents'));
        $world->quotas()->lock(Uuid::fromString(Fleet::OTHER_TEAM));
        self::assertSame(['fleet.team:'.Fleet::OTHER_TEAM], $world->lock->keys);
    }

    private function approved(FleetWorld $world): Agent
    {
        $agent = Fleet::approved();
        $world->agents->add($agent);

        return $agent;
    }

    /**
     * @param list<array<string, mixed>> $results
     */
    private function poll(array $results = [], string $version = '0.1.0', int $configVersion = 0): RecordAgentPoll
    {
        return new RecordAgentPoll(Fleet::AGENT, (string) json_encode(['protocol_version' => 1, 'agent_version' => $version, 'config_version' => $configVersion, 'order_results' => $results]));
    }

    private function answer(FleetWorld $world): PollAnswer
    {
        return new GetPollAnswerHandler($world->agents, $world->orders, new OrderSigner($world->signer), $world->clock)(new GetPollAnswer(Fleet::AGENT));
    }
}
