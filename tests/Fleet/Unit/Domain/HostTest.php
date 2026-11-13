<?php

declare(strict_types=1);

namespace Tests\Fleet\Unit\Domain;

use Fleet\Domain\Event\HostLabelled;
use Fleet\Domain\Model\AgentMode;
use Fleet\Domain\Model\AgentRuntime;
use Fleet\Domain\Model\AgentScope;
use Fleet\Domain\Model\HostFacts;
use Fleet\Domain\Model\HostIdentity;
use Fleet\Domain\Model\HostLabel;
use Fleet\Domain\Model\InvalidEnrollmentPayload;
use Fleet\Domain\Model\InvalidHostLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Fleet\Support\AssertsRefusals;
use Tests\Fleet\Support\Fleet;

use function Symfony\Component\String\u;

final class HostTest extends TestCase
{
    use AssertsRefusals;

    #[Test]
    public function it_should_group_the_agents_of_a_machine_by_engine_or_machine_id(): void
    {
        $facts = Fleet::facts();
        self::assertEquals(new HostIdentity('docker', 'b1c7a0f2-3e4d-4c5b-9a8f-7e6d5c4b3a21'), $facts->identityFor(AgentMode::Container, 'ed25519:key'));
        self::assertEquals(new HostIdentity('machine', Fleet::MACHINE), $facts->identityFor(AgentMode::Host, 'ed25519:key'));

        $noDocker = new HostFacts('web-01', machineIdHash: Fleet::MACHINE);
        self::assertEquals(new HostIdentity('machine', Fleet::MACHINE), $noDocker->identityFor(AgentMode::Container, 'ed25519:key'));
        $noMachine = new HostFacts('web-01', dockerEngineId: 'engine');
        self::assertEquals(new HostIdentity('docker', 'engine'), $noMachine->identityFor(AgentMode::Host, 'ed25519:key'));
        $empty = new HostFacts('Web-01', dockerEngineId: '');
        self::assertEquals(new HostIdentity('hostname', 'web-01'), $empty->identityFor(AgentMode::Container, 'ed25519:key'));
        self::assertEquals(new HostIdentity('hostname', 'web-01'), $empty->identityFor(AgentMode::Host, 'ed25519:key'));
        self::assertEquals(new HostIdentity('embedded', 'ed25519:key'), $facts->identityFor(AgentMode::Embedded, 'ed25519:key'), 'An embedded agent is its own host.');
    }

    #[Test]
    public function it_should_keep_the_facts_of_the_last_enrollment(): void
    {
        $host = Fleet::host();
        self::assertSame([Fleet::HOST, Fleet::TEAM], [$host->id()->toString(), $host->teamId()->toRfc4122()]);
        self::assertEquals(Fleet::facts()->identityFor(AgentMode::Container, 'ed25519:key'), $host->identity());
        self::assertEquals(Fleet::now(), $host->createdAt());
        self::assertNull($host->lastSeenAt());

        $later = Fleet::now()->modify('+1 day');
        $host->refresh(new HostFacts('web-01-renamed'), $later);
        self::assertSame('web-01-renamed', $host->facts()->hostname);
        self::assertEquals($later, $host->lastSeenAt());
        $host->seen($later->modify('+1 hour'));
        self::assertEquals($later->modify('+1 hour'), $host->lastSeenAt());
    }

    #[Test]
    public function it_should_take_a_display_name_and_addresses_from_a_human(): void
    {
        $host = Fleet::host();
        self::assertSame(['web-01', null, []], [$host->name(), $host->label()->displayName, $host->label()->addresses]);

        $host->relabel(new HostLabel('  Shop front  ', [' 10.0.0.1 ', '2001:DB8:0:0:0:0:0:1', '10.0.0.1', '2001:db8::1']));
        self::assertSame(['Shop front', ['10.0.0.1', '2001:db8::1']], [$host->name(), $host->label()->addresses], 'Trimmed, canonical, each once.');
        self::assertEquals([new HostLabelled(Fleet::HOST, Fleet::TEAM, 'Shop front', ['10.0.0.1', '2001:db8::1'])], $host->releaseEvents());

        $host->relabel(new HostLabel('Shop front', ['10.0.0.1', '2001:db8::1']));
        self::assertSame([], $host->releaseEvents(), 'Unchanged: nothing published.');
        self::assertSame(['10.0.0.1', '10.0.0.2'], new HostLabel(null, ['10.0.0.1', '10.0.0.1', '10.0.0.2'])->addresses, 'A list, without holes.');
        $host->relabel(new HostLabel('Shop front', ['2001:db8::1', '10.0.0.1']));
        self::assertCount(1, $host->releaseEvents(), 'Another order is a change.');
        $host->relabel(new HostLabel('Shop', ['2001:db8::1', '10.0.0.1']));
        self::assertCount(1, $host->releaseEvents(), 'Another name is a change.');

        $host->relabel(new HostLabel(' ', []));
        self::assertSame(['web-01', null, []], [$host->name(), $host->label()->displayName, $host->label()->addresses], 'Empty: its hostname again.');
        self::assertEquals([new HostLabelled(Fleet::HOST, Fleet::TEAM, null, [])], $host->releaseEvents());
        self::assertNull(new HostLabel(null, [])->displayName);

        self::assertSame(u('a')->repeat(100)->toString(), new HostLabel(u('a')->repeat(100)->toString(), [])->displayName);
        self::assertCount(16, new HostLabel(null, array_map(static fn (int $i): string => '10.0.0.'.$i, range(1, 16)))->addresses);
        self::assertCount(16, new HostLabel(null, [...array_map(static fn (int $i): string => '10.0.0.'.$i, range(1, 16)), '10.0.0.1'])->addresses, 'Counted once unique.');
        foreach ([
            static fn () => new HostLabel(u('a')->repeat(101)->toString(), []),
            static fn () => new HostLabel("shop\u{0007}", []),
            static fn () => new HostLabel(null, ['10.0.0.256']),
            static fn () => new HostLabel(null, ['web-01']),
            static fn () => new HostLabel(null, ['']),
            static fn () => new HostLabel(null, ['10.0.0.0/24']),
            static fn () => new HostLabel(null, array_map(static fn (int $i): string => '10.0.0.'.$i, range(1, 17))),
        ] as $label) {
            $this->assertRefused(InvalidHostLabel::class, 'invalid_host_label', $label);
        }
    }

    /**
     * @return iterable<string, array{\Closure(): mixed}>
     */
    public static function invalidFacts(): iterable
    {
        $long = u('a')->repeat(256)->toString();
        yield 'blank hostname' => [static fn () => new HostFacts('  ')];
        yield 'long hostname' => [static fn () => new HostFacts($long)];
        yield 'long os id' => [static fn () => new HostFacts('h', osId: $long)];
        yield 'long os version' => [static fn () => new HostFacts('h', osVersionId: $long)];
        yield 'long pretty name' => [static fn () => new HostFacts('h', osPrettyName: $long)];
        yield 'long kernel' => [static fn () => new HostFacts('h', kernel: $long)];
        yield 'long arch' => [static fn () => new HostFacts('h', arch: $long)];
        yield 'long engine' => [static fn () => new HostFacts('h', dockerEngineId: $long)];
        yield 'machine id not hex' => [static fn () => new HostFacts('h', machineIdHash: u('z')->repeat(64)->toString())];
        yield 'machine id short' => [static fn () => new HostFacts('h', machineIdHash: u('a')->repeat(63)->toString())];
        yield 'machine id uppercase' => [static fn () => new HostFacts('h', machineIdHash: u('A')->repeat(64)->toString())];
        yield 'host mode with scope' => [static fn () => new AgentRuntime(AgentMode::Host, AgentScope::Host, null, '0.1.0')];
        yield 'container without scope' => [static fn () => new AgentRuntime(AgentMode::Container, null, null, '0.1.0')];
        yield 'compose scope without project' => [static fn () => new AgentRuntime(AgentMode::Container, AgentScope::Compose, null, '0.1.0')];
        yield 'host scope with project' => [static fn () => new AgentRuntime(AgentMode::Container, AgentScope::Host, 'shop', '0.1.0')];
        yield 'empty project' => [static fn () => new AgentRuntime(AgentMode::Container, AgentScope::Labeled, '', '0.1.0')];
        yield 'long project' => [static fn () => new AgentRuntime(AgentMode::Container, AgentScope::Labeled, $long, '0.1.0')];
        yield 'not semver' => [static fn () => new AgentRuntime(AgentMode::Host, null, null, 'v1')];
        yield 'semver with garbage' => [static fn () => AgentRuntime::version('0.1.0 ')];
        yield 'leading garbage' => [static fn () => AgentRuntime::version('x0.1.0')];
        yield 'huge numbers' => [static fn () => AgentRuntime::version('123456.0.0')];
    }

    /**
     * @param \Closure(): mixed $build
     */
    #[Test]
    #[DataProvider('invalidFacts')]
    public function it_should_refuse_facts_out_of_the_protocol_bounds(\Closure $build): void
    {
        $this->assertRefused(InvalidEnrollmentPayload::class, 'invalid_payload', $build);
    }

    #[Test]
    public function it_should_accept_the_bounds_of_the_facts(): void
    {
        $max = u('a')->repeat(255)->toString();
        self::assertSame($max, new HostFacts($max, $max, $max, $max, $max, $max, $max, u('0')->repeat(64)->toString())->hostname);
        self::assertSame($max, new AgentRuntime(AgentMode::Container, AgentScope::Labeled, $max, '1.2.3-rc.1+build.5')->composeProject);
        self::assertNull(new AgentRuntime(AgentMode::Container, AgentScope::Host, null, '0.1.0')->composeProject);
        self::assertSame('0.1.0', new AgentRuntime(AgentMode::Host, null, null, '0.1.0')->version);
        self::assertSame('99999.99999.99999', AgentRuntime::version('99999.99999.99999'));
    }
}
