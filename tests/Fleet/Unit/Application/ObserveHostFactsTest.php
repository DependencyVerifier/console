<?php

declare(strict_types=1);

namespace Tests\Fleet\Unit\Application;

use Fleet\Application\Command\Host\Handler\ObserveHostFactsHandler;
use Fleet\Application\Command\Host\ObserveHostFacts;
use Fleet\Application\EventHandler\ObserveHostFactsOnInventory;
use Fleet\Domain\Model\AgentMode;
use Fleet\Domain\Model\Host;
use Fleet\Domain\Model\HostFacts;
use Fleet\Domain\Model\HostId;
use Fleet\Domain\Model\HostIdentity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Audit\Outcome;
use Shared\Application\Audit\SelfAuditedCommand;
use Shared\Application\Audit\Target;
use Shared\Application\Contract\Inventory\HostFactsObserved;
use Symfony\Component\Uid\Uuid;
use Tests\Fleet\Support\Fleet;
use Tests\Fleet\Support\FleetWorld;
use Tests\Fleet\Support\RecordingLogger;
use Tests\Identity\Support\RecordingAudit;
use Tests\Organization\Support\RecordingCommands;

use function Symfony\Component\String\u;

/**
 * The facts an inventory observed, kept by Fleet: merged under the team lock, for a host of the team whose agent still
 * reports for it; a host known by its hostname alone promoted to its Docker engine unless another host holds it.
 */
final class ObserveHostFactsTest extends TestCase
{
    private const string OTHER_HOST = '019a6b2c-7f00-7a11-8b22-3c44d55e6f02';

    private FleetWorld $world;
    private RecordingAudit $audit;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->world = new FleetWorld();
        $this->audit = new RecordingAudit();
        $this->logger = new RecordingLogger();
    }

    #[Test]
    public function it_should_copy_the_published_facts_into_a_command(): void
    {
        $commands = new RecordingCommands();
        new ObserveHostFactsOnInventory($commands)(new HostFactsObserved(Fleet::HOST, Fleet::TEAM, Fleet::AGENT, 'YggWeb', 'debian', '13', 'Debian GNU/Linux 13 (trixie)', '6.17', 'x86_64', 'engine-1'));
        self::assertEquals([new ObserveHostFacts(Fleet::HOST, Fleet::TEAM, Fleet::AGENT, 'YggWeb', 'debian', '13', 'Debian GNU/Linux 13 (trixie)', '6.17', 'x86_64', 'engine-1')], $commands->dispatched);
    }

    #[Test]
    public function it_should_merge_the_facts_of_a_host_under_the_team_lock(): void
    {
        $this->world->hosts->add(Fleet::host());
        $this->world->agents->add(Fleet::approved());

        ($this->handler())($this->command(hostname: 'YggWeb', osPrettyName: 'Debian GNU/Linux 13 (trixie)', kernel: '6.17.13-2-pve'));

        $host = $this->world->hosts->find(HostId::fromString(Fleet::HOST));
        self::assertSame(['YggWeb', 'debian', 'Debian GNU/Linux 13 (trixie)', '6.17.13-2-pve'], [$host?->facts()->hostname, $host?->facts()->osId, $host?->facts()->osPrettyName, $host?->facts()->kernel]);
        self::assertSame(['fleet.team:'.Fleet::TEAM], $this->world->lock->keys);
        self::assertSame([], $this->entries(), 'No entry per inventory.');
        self::assertEquals(Fleet::facts()->identityFor(AgentMode::Container, 'ed25519:key'), $host?->identity(), 'A Docker identity stays.');
    }

    #[Test]
    public function it_should_ignore_the_facts_of_a_host_gone_or_of_an_agent_that_no_longer_reports_for_it(): void
    {
        ($this->handler())($this->command(hostname: 'YggWeb'));
        self::assertSame([], $this->entries(), 'No host: deleted while the inventory waited.');

        $this->world->hosts->add(Fleet::host());
        ($this->handler())($this->command(hostname: 'YggWeb'));
        self::assertSame('web-01', $this->hostname(), 'No agent.');

        $agent = Fleet::approved();
        $this->world->agents->add($agent);
        ($this->handler())(new ObserveHostFacts(Fleet::HOST, Fleet::OTHER_TEAM, Fleet::AGENT, 'YggWeb', null, null, null, null, null, null));
        self::assertSame('web-01', $this->hostname(), 'Another team: its real team decides.');

        $agent->revoke(Fleet::now()->modify('+1 minute'));
        ($this->handler())($this->command(hostname: 'YggWeb'));
        self::assertSame('web-01', $this->hostname(), 'Revoked while its inventory waited.');
        self::assertSame([], $this->entries());
    }

    #[Test]
    public function it_should_drop_facts_out_of_bounds_and_log_the_field_only(): void
    {
        $this->world->hosts->add(Fleet::host());
        $this->world->agents->add(Fleet::approved());
        $secret = "web\u{202E}evil";

        ($this->handler())($this->command(hostname: 'YggWeb', kernel: $secret));

        self::assertSame('web-01', $this->hostname(), 'Nothing merged.');
        self::assertCount(1, $this->logs());
        [$level, $message, $context] = $this->logs()[0];
        self::assertSame(['warning', ['host_id' => Fleet::HOST, 'field' => 'kernel']], [$level, $context]);
        self::assertStringNotContainsString($secret, $message.json_encode($context), 'Never the value.');
        self::assertSame([], $this->entries());
        $this->logger = new RecordingLogger();
        ($this->handler())($this->command(hostname: u('a')->repeat(256)->toString()));
        self::assertSame('hostname', $this->logs()[0][2]['field'] ?? null);
    }

    #[Test]
    public function it_should_promote_a_host_known_by_its_hostname_to_its_docker_engine_once(): void
    {
        $this->world->hosts->add($this->hostnameHost(Fleet::HOST, Fleet::TEAM));
        $this->world->agents->add(Fleet::approved());

        ($this->handler())($this->command(hostname: 'YggWeb'));
        self::assertTrue($this->identity()->isHostname(), 'No engine id observed: no promotion.');
        self::assertSame([], $this->entries());

        ($this->handler())($this->command(hostname: 'YggWeb', dockerEngineId: 'engine-1'));
        self::assertEquals(HostIdentity::docker('engine-1'), $this->identity());
        self::assertCount(1, $this->entries());
        [$command, $outcome] = $this->entries()[0];
        self::assertEquals(Outcome::success(), $outcome);
        self::assertSame('fleet.host_identity_promoted', $command->auditAction()->name);
        self::assertEquals(Target::of('host', Fleet::HOST), $command->auditTarget());
        self::assertSame(['agent_id' => Fleet::AGENT, 'from_kind' => 'hostname', 'to_kind' => 'docker'], $command->auditContext()->values, 'Who promoted it; never the engine id.');

        ($this->handler())($this->command(dockerEngineId: 'engine-2'));
        self::assertEquals(HostIdentity::docker('engine-1'), $this->identity(), 'Promoted once.');
        self::assertCount(1, $this->entries());
    }

    #[Test]
    public function it_should_not_promote_onto_an_identity_another_host_of_the_team_holds(): void
    {
        $this->world->hosts->add($this->hostnameHost(Fleet::HOST, Fleet::TEAM));
        $this->world->hosts->add(Host::register(HostId::fromString(self::OTHER_HOST), Uuid::fromString(Fleet::TEAM), HostIdentity::docker('engine-1'), new HostFacts('web-02', dockerEngineId: 'engine-1'), Fleet::now()));
        $this->world->agents->add(Fleet::approved());

        ($this->handler())($this->command(hostname: 'YggWeb', dockerEngineId: 'engine-1'));

        self::assertTrue($this->identity()->isHostname(), 'Never two hosts of one identity: no merge either.');
        self::assertSame('YggWeb', $this->hostname(), 'The facts are still merged.');
        self::assertSame([], $this->entries());
    }

    #[Test]
    public function it_should_promote_even_when_another_team_holds_the_identity(): void
    {
        $this->world->hosts->add($this->hostnameHost(Fleet::HOST, Fleet::TEAM));
        $this->world->hosts->add(Host::register(HostId::fromString(self::OTHER_HOST), Uuid::fromString(Fleet::OTHER_TEAM), HostIdentity::docker('engine-1'), new HostFacts('web-02', dockerEngineId: 'engine-1'), Fleet::now()));
        $this->world->agents->add(Fleet::approved());

        ($this->handler())($this->command(dockerEngineId: 'engine-1'));

        self::assertEquals(HostIdentity::docker('engine-1'), $this->identity(), 'An identity is unique per team.');
    }

    private function handler(): ObserveHostFactsHandler
    {
        return new ObserveHostFactsHandler($this->world->hosts, $this->world->agents, $this->world->quotas(), $this->audit, $this->logger);
    }

    /**
     * @return list<array{SelfAuditedCommand, Outcome}>
     */
    private function entries(): array
    {
        return $this->audit->entries;
    }

    /**
     * @return list<array{string, string, array<mixed>}>
     */
    private function logs(): array
    {
        return $this->logger->logs;
    }

    private function command(?string $hostname = null, ?string $osPrettyName = null, ?string $kernel = null, ?string $dockerEngineId = null): ObserveHostFacts
    {
        return new ObserveHostFacts(Fleet::HOST, Fleet::TEAM, Fleet::AGENT, $hostname, null, null, $osPrettyName, $kernel, null, $dockerEngineId);
    }

    private function hostnameHost(string $id, string $teamId): Host
    {
        return Host::register(HostId::fromString($id), Uuid::fromString($teamId), new HostIdentity('hostname', 'd0f0663a8c03'), new HostFacts('d0f0663a8c03'), Fleet::now());
    }

    private function hostname(): ?string
    {
        return $this->world->hosts->find(HostId::fromString(Fleet::HOST))?->facts()->hostname;
    }

    private function identity(): HostIdentity
    {
        return $this->world->hosts->find(HostId::fromString(Fleet::HOST))?->identity() ?? self::fail('No host.');
    }
}
