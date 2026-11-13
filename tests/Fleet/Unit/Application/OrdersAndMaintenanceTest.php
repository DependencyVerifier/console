<?php

declare(strict_types=1);

namespace Tests\Fleet\Unit\Application;

use Fleet\Application\Command\Agent\Handler\AgentRevocation;
use Fleet\Application\Command\Maintenance\Handler\MaintainFleetHandler;
use Fleet\Application\Command\Maintenance\MaintainFleet;
use Fleet\Application\Command\Maintenance\MaintenanceReport;
use Fleet\Application\Command\Order\ConfigureAgent;
use Fleet\Application\Command\Order\Handler\ConfigureAgentHandler;
use Fleet\Application\Command\Order\Handler\RequestScanHandler;
use Fleet\Application\Command\Order\Handler\UpgradeAgentHandler;
use Fleet\Application\Command\Order\RequestScan;
use Fleet\Application\Command\Order\UpgradeAgent;
use Fleet\Application\Query\Team\Handler\ListAgentOrdersHandler;
use Fleet\Application\Query\Team\ListAgentOrders;
use Fleet\Application\Query\View\OrderRow;
use Fleet\Application\Release\AgentRelease;
use Fleet\Domain\Model\Agent;
use Fleet\Domain\Model\AgentConfig;
use Fleet\Domain\Model\AgentNotApproved;
use Fleet\Domain\Model\AgentNotUpgradable;
use Fleet\Domain\Model\AgentOrder;
use Fleet\Domain\Model\AgentOrderId;
use Fleet\Domain\Model\AgentStatus;
use Fleet\Domain\Model\OrderStatus;
use Fleet\Domain\Model\OrderType;
use Fleet\Domain\Model\ScanAlreadyRequested;
use Fleet\Domain\Model\ScanIntervalBelowMinimum;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Audit\Target;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Contract\Organization\TeamAccess;
use Shared\Application\Query\Page;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;
use Tests\Fleet\Support\FixedReleases;
use Tests\Fleet\Support\Fleet;
use Tests\Fleet\Support\FleetWorld;
use Tests\Fleet\Support\InMemoryOrders;
use Tests\Fleet\Support\RecordingFleetReadModel;

final class OrdersAndMaintenanceTest extends TestCase
{
    private const string ORDER_2 = '019a6b2c-9f00-7a11-8b22-3c44d55e6f02';

    #[Test]
    public function it_should_queue_a_scan_once_every_five_minutes(): void
    {
        $world = new FleetWorld()->as('team_member', Permission::HostOrder);
        $agent = Fleet::approved();
        $world->agents->add($agent);
        $scan = $this->scan($world);

        $scan(new RequestScan(Fleet::ORDER, Fleet::AGENT));
        $order = $world->orders->find(AgentOrderId::fromString(Fleet::ORDER));
        self::assertNotNull($order);
        self::assertSame([OrderType::ScanNow, OrderStatus::Queued, Fleet::ADMIN], [$order->type(), $order->status(), $order->requestedBy()?->toRfc4122()]);

        $world->clock->modify('+4 minutes 59 seconds');
        $this->assertRefused(ScanAlreadyRequested::class, static fn () => $scan(new RequestScan(self::ORDER_2, Fleet::AGENT)));
        $world->clock->modify('+1 second');
        $scan(new RequestScan(self::ORDER_2, Fleet::AGENT));
        self::assertCount(2, $world->orders->orders);

        $command = new RequestScan(Fleet::ORDER, Fleet::AGENT);
        self::assertSame(['fleet.request_scan', ['order_id' => Fleet::ORDER]], [$command->auditAction()->name, $command->auditContext()->values]);
        self::assertEquals(Target::of('agent', Fleet::AGENT), $command->auditTarget());
    }

    #[Test]
    public function it_should_scan_approved_agents_of_the_actor_teams_only(): void
    {
        $world = new FleetWorld()->as('team_member', Permission::HostOrder);
        $world->agents->add(Fleet::agent());
        $scan = $this->scan($world);
        $this->assertRefused(AgentNotApproved::class, static fn () => $scan(new RequestScan(Fleet::ORDER, Fleet::AGENT)));
        $this->assertRefused(ResourceNotFound::class, static fn () => $scan(new RequestScan(Fleet::ORDER, Fleet::HOST)));
        $world->as('team_viewer');
        $this->assertRefused(PermissionDenied::class, static fn () => $scan(new RequestScan(Fleet::ORDER, Fleet::AGENT)));
        self::assertSame([], $world->orders->orders);
    }

    #[Test]
    public function it_should_send_a_new_configuration_never_below_the_team_minimum(): void
    {
        $world = new FleetWorld()->as('team_admin', Permission::HostOrder);
        $agent = Fleet::approved();
        $world->agents->add($agent);
        $configure = $this->configure($world);
        $world->limits['min_scan_interval_minutes'] = 60;

        try {
            $configure(new ConfigureAgent(Fleet::ORDER, Fleet::AGENT, 59));
            self::fail('Below the minimum.');
        } catch (ScanIntervalBelowMinimum $below) {
            self::assertSame([60, 'scan_interval_below_minimum'], [$below->minimum, $below->ruleKey()]);
        }

        self::assertNull($agent->desiredConfig());
        $configure(new ConfigureAgent(Fleet::ORDER, Fleet::AGENT, 60, ['/srv'], ['/opt/app'], ['/srv/**/cache']));
        $order = $world->orders->find(AgentOrderId::fromString(Fleet::ORDER));
        self::assertNotNull($order);
        self::assertSame(['config_version' => 1, 'scan_interval_minutes' => 60, 'discovery_roots' => ['/srv'], 'declared_paths' => ['/opt/app'], 'exclusions' => ['/srv/**/cache']], $order->payload());
        self::assertSame(Fleet::ADMIN, $order->requestedBy()?->toRfc4122());
        self::assertEquals(new AgentConfig(60, ['/srv'], ['/opt/app'], ['/srv/**/cache'], 1), $agent->desiredConfig());

        $configure(new ConfigureAgent(self::ORDER_2, Fleet::AGENT, 120));
        self::assertSame(OrderStatus::Cancelled, $order->status(), 'Not yet signed: only the newest matters.');
        self::assertSame(2, $world->orders->find(AgentOrderId::fromString(self::ORDER_2))?->payload()['config_version']);

        unset($world->limits['min_scan_interval_minutes']);
        $configure(new ConfigureAgent('019a6b2c-9f00-7a11-8b22-3c44d55e6f03', Fleet::AGENT, 5));
        self::assertSame(3, $world->agents->find($agent->id())?->desiredConfig()?->version);

        $command = new ConfigureAgent(Fleet::ORDER, Fleet::AGENT, 60);
        self::assertSame(['fleet.configure_agent', ['order_id' => Fleet::ORDER, 'scan_interval_minutes' => 60]], [$command->auditAction()->name, $command->auditContext()->values]);
        self::assertEquals(Target::of('agent', Fleet::AGENT), $command->auditTarget());
    }

    #[Test]
    public function it_should_keep_a_signed_configuration_and_other_orders(): void
    {
        $world = new FleetWorld()->as('team_admin', Permission::HostOrder);
        $agent = Fleet::approved();
        $world->agents->add($agent);
        $delivered = AgentOrder::setConfig(AgentOrderId::fromString(Fleet::ORDER), $agent, new AgentConfig(60, version: 1), null, Fleet::now());
        $delivered->issue(Fleet::now());
        $delivered->deliver('s');
        $scan = AgentOrder::scanNow(AgentOrderId::fromString(self::ORDER_2), $agent, null, Fleet::now());
        $world->orders->add($delivered);
        $world->orders->add($scan);

        $this->configure($world)(new ConfigureAgent('019a6b2c-9f00-7a11-8b22-3c44d55e6f03', Fleet::AGENT, 90));
        self::assertSame([OrderStatus::Delivered, OrderStatus::Queued], [$delivered->status(), $scan->status()]);
    }

    #[Test]
    public function it_should_configure_approved_agents_of_the_actor_teams_only(): void
    {
        $world = new FleetWorld()->as('team_admin', Permission::HostOrder);
        $pending = Fleet::agent();
        $world->agents->add($pending);
        $configure = $this->configure($world);
        $this->assertRefused(AgentNotApproved::class, static fn () => $configure(new ConfigureAgent(Fleet::ORDER, Fleet::AGENT, 60)));
        self::assertNull($pending->desiredConfig(), 'Unchanged.');
        $this->assertRefused(ResourceNotFound::class, static fn () => $configure(new ConfigureAgent(Fleet::ORDER, Fleet::HOST, 60)));
        $world->as('team_viewer');
        $this->assertRefused(PermissionDenied::class, static fn () => $configure(new ConfigureAgent(Fleet::ORDER, Fleet::AGENT, 60)));
    }

    #[Test]
    public function it_should_maintain_the_fleet_by_batches(): void
    {
        $world = new FleetWorld();
        $silent = Fleet::approved();
        $fresh = Fleet::approvedWithId('019a6b2c-8f00-7a11-8b22-3c44d55e6f02');
        $forgotten = Fleet::approvedWithId('019a6b2c-8f00-7a11-8b22-3c44d55e6f03');
        $forgotten->markInactiveIfSilent(Fleet::now()->modify('+7 days'));
        array_map($world->agents->add(...), [$forgotten, $silent, $fresh]);
        $late = AgentOrder::scanNow(AgentOrderId::fromString(Fleet::ORDER), $fresh, null, Fleet::now());
        $world->orders->add($late);
        $world->nonces->remember('k', 'old', Fleet::now());

        $world->clock->modify('+7 days');
        $fresh->seen('0.1.0', 0, $world->clock->now()->modify('-1 second'));
        $late->issue($world->clock->now()->modify('-16 minutes'));
        $late->deliver('s');
        $world->nonces->remember('k', 'new', $world->clock->now()->modify('-9 minutes'));
        $report = $this->maintain($world, 1);

        self::assertSame([AgentStatus::Inactive, AgentStatus::Approved, AgentStatus::Inactive], [$silent->status(), $fresh->status(), $forgotten->status()]);
        self::assertSame([1, 0, 1, 1], [$report->inactive, $report->revoked, $report->expiredOrders, $report->purgedNonces]);
        self::assertSame(OrderStatus::Expired, $late->status());
        self::assertSame(['k new'], array_keys($world->nonces->seen));

        $world->clock->modify('+30 days');
        $report = $this->maintain($world, 1);
        self::assertSame(AgentStatus::Revoked, $forgotten->status(), 'Inactive for 30 days more: revoked.');
        self::assertSame([1, 1], [$report->revoked, $report->inactive], 'One of each per batch of 1.');
        self::assertSame(AgentStatus::Inactive, $fresh->status());
        self::assertSame(AgentStatus::Inactive, $silent->status(), 'Inactive as long, but the batch of 1 was full: the next run takes it.');

        $command = new MaintainFleet();
        self::assertSame(['fleet.maintain', 500, []], [$command->auditAction()->name, $command->batch, $command->auditContext()->values]);
        self::assertTrue($command->auditTarget()->isNone());
    }

    #[Test]
    public function it_should_keep_a_delivered_order_within_its_grace(): void
    {
        $world = new FleetWorld();
        $agent = Fleet::approved();
        $world->agents->add($agent);
        $order = AgentOrder::scanNow(AgentOrderId::fromString(Fleet::ORDER), $agent, null, Fleet::now());
        $order->issue(Fleet::now());
        $order->deliver('s');
        $world->orders->add($order);
        $world->clock->modify('+15 minutes 59 seconds');

        self::assertSame(0, $this->maintain($world, 10)->expiredOrders);
        self::assertSame(OrderStatus::Delivered, $order->status());
    }

    #[Test]
    public function it_should_list_the_orders_of_an_agent_to_its_team(): void
    {
        $world = new FleetWorld()->as('team_viewer');
        $world->agents->add(Fleet::agent());
        $read = new RecordingFleetReadModel();
        $read->orders = [new OrderRow(Fleet::ORDER, 'SCAN_NOW', 'executed', Fleet::now(), Fleet::now(), Fleet::now(), null)];
        $handler = new ListAgentOrdersHandler($world->access(), $world->agents, $read);

        self::assertEquals(new Page($read->orders, 1, 9), $handler(new ListAgentOrders(Fleet::AGENT)));
        self::assertSame([['orders', Fleet::AGENT]], $read->calls);
        $this->assertRefused(ResourceNotFound::class, static fn () => $handler(new ListAgentOrders('nope')));
        $this->assertRefused(ResourceNotFound::class, static fn () => $handler(new ListAgentOrders(Fleet::HOST)));
        $world->as('team_guest');
        $this->assertRefused(PermissionDenied::class, static fn () => $handler(new ListAgentOrders(Fleet::AGENT)));
    }

    #[Test]
    public function it_should_queue_an_upgrade_for_the_host_agents_behind_the_release(): void
    {
        $world = new FleetWorld()->as('team_admin', Permission::HostOrder);
        $releases = new FixedReleases(new AgentRelease('0.2.0', []));
        $upgrade = new UpgradeAgentHandler($world->access(), $world->agents, $world->orders, $releases, $world->actor, $world->clock);
        $approved = static function (string $version = '0.1.0'): Agent {
            $agent = Fleet::hostAgent($version);
            $agent->approve(Uuid::fromString(Fleet::ADMIN), Fleet::now());

            return $agent;
        };
        [$current, $behind, $alsoBehind] = [$approved('0.2.0'), $approved(), $approved()];
        $pending = Fleet::hostAgent();
        $container = Fleet::approved();
        foreach ([$current, $behind, $pending, $container, $alsoBehind] as $agent) {
            $world->agents->add($agent);
        }
        $order = static fn (Agent $agent): string => $agent->id()->toString().' UPGRADE_AGENT {"version":"0.2.0"}';

        $upgrade(new UpgradeAgent(Fleet::TEAM, $behind->id()->toString()));
        self::assertSame([$order($behind)], self::queued($world->orders), 'One agent.');
        $upgrade(new UpgradeAgent(Fleet::TEAM));
        self::assertSame([$order($behind), $order($alsoBehind)], self::queued($world->orders), 'The approved host agents behind the release, but the one already waiting.');
        $first = array_first($world->orders->orders);
        self::assertNotNull($first);
        $first->cancel();
        $upgrade(new UpgradeAgent(Fleet::TEAM, $behind->id()->toString()));
        self::assertSame([$order($behind), $order($alsoBehind), $order($behind)], self::queued($world->orders), 'Once the first one is no longer waiting.');
        self::assertEquals(Uuid::fromString(Fleet::ADMIN), array_values($world->orders->orders)[2]->requestedBy());

        foreach ([$current, $container] as $refused) {
            $this->assertRefused(AgentNotUpgradable::class, static fn () => $upgrade(new UpgradeAgent(Fleet::TEAM, $refused->id()->toString())));
        }
        $this->assertRefused(AgentNotApproved::class, static fn () => $upgrade(new UpgradeAgent(Fleet::TEAM, $pending->id()->toString())));
        $this->assertRefused(ResourceNotFound::class, static fn () => $upgrade(new UpgradeAgent(Fleet::TEAM, 'not-a-uuid')));
        $world->teams[Fleet::OTHER_TEAM] = new TeamAccess(Fleet::OTHER_TEAM, 'Other', 'team_admin', false);
        $world->permissions->team[Fleet::OTHER_TEAM] = [Permission::HostOrder];
        $this->assertRefused(ResourceNotFound::class, static fn () => $upgrade(new UpgradeAgent(Fleet::OTHER_TEAM, $alsoBehind->id()->toString())));
        self::assertCount(3, $world->orders->orders, 'An agent of another team, even to an actor of both.');
        $releases->latest = null;
        $this->assertRefused(AgentNotUpgradable::class, static fn () => $upgrade(new UpgradeAgent(Fleet::TEAM)));
        $releases->latest = new AgentRelease('0.2.0', []);
        $world->as('team_member');
        $this->assertRefused(PermissionDenied::class, static fn () => $upgrade(new UpgradeAgent(Fleet::TEAM)));
        try {
            AgentOrder::upgradeAgent(AgentOrderId::of(Uuid::v7()), $behind, '0.2', null, Fleet::now());
            self::fail('Not a version.');
        } catch (AgentNotUpgradable $refused) {
            self::assertSame('agent_not_upgradable', $refused->ruleKey());
        }

        $command = new UpgradeAgent(Fleet::TEAM, $behind->id()->toString());
        self::assertSame(['fleet.upgrade_agent', []], [$command->auditAction()->name, $command->auditContext()->values]);
        self::assertEquals([Target::of('agent', $behind->id()->toString()), Target::of('team', Fleet::TEAM)], [$command->auditTarget(), new UpgradeAgent(Fleet::TEAM)->auditTarget()]);
    }

    /**
     * @return list<string> agent, type and payload of each order, in creation order
     */
    private static function queued(InMemoryOrders $orders): array
    {
        return array_values(array_map(static fn (AgentOrder $o): string => $o->agentId()->toString().' '.$o->type()->value.' '.json_encode($o->payload()), $orders->orders));
    }

    private function scan(FleetWorld $world): RequestScanHandler
    {
        return new RequestScanHandler($world->access(), $world->agents, $world->orders, $world->actor, $world->clock);
    }

    private function configure(FleetWorld $world): ConfigureAgentHandler
    {
        return new ConfigureAgentHandler($world->access(), $world->agents, $world->orders, $world->quotas(), $world->actor, $world->clock);
    }

    private function maintain(FleetWorld $world, int $batch): MaintenanceReport
    {
        $command = new MaintainFleet(batch: $batch);
        new MaintainFleetHandler($world->agents, $world->orders, new AgentRevocation($world->orders, $world->clock), $world->nonces, $world->clock)($command);

        return $command->report;
    }

    /**
     * @param class-string<\Throwable> $class
     * @param \Closure(): mixed        $gesture
     */
    private function assertRefused(string $class, \Closure $gesture): void
    {
        try {
            $gesture();
            self::fail('Expected '.$class);
        } catch (\Throwable $refused) {
            self::assertInstanceOf($class, $refused, $refused->getMessage());
        }
    }
}
