<?php

declare(strict_types=1);

namespace Tests\Fleet\Unit\Domain;

use Fleet\Domain\Event\HostLabelled;
use Fleet\Domain\Model\AgentMode;
use Fleet\Domain\Model\AgentRuntime;
use Fleet\Domain\Model\AgentScope;
use Fleet\Domain\Model\Host;
use Fleet\Domain\Model\HostFacts;
use Fleet\Domain\Model\HostId;
use Fleet\Domain\Model\HostIdentity;
use Fleet\Domain\Model\HostLabel;
use Fleet\Domain\Model\InvalidEnrollmentPayload;
use Fleet\Domain\Model\InvalidHostFacts;
use Fleet\Domain\Model\InvalidHostLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
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

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function observedFields(): iterable
    {
        yield 'hostname' => ['hostname', 'YggWeb', 'hostname'];
        yield 'os id' => ['osId', 'ubuntu', 'osId'];
        yield 'os version' => ['osVersionId', '24.04', 'osVersionId'];
        yield 'pretty name' => ['osPrettyName', 'Debian GNU/Linux 13 (trixie)', 'osPrettyName'];
        yield 'kernel' => ['kernel', '6.17.13-2-pve', 'kernel'];
        yield 'arch' => ['arch', 'aarch64', 'arch'];
        yield 'engine' => ['dockerEngineId', 'engine-2', 'dockerEngineId'];
    }

    #[Test]
    #[DataProvider('observedFields')]
    public function it_should_merge_each_observed_field_alone(string $argument, string $value, string $property): void
    {
        $facts = Fleet::facts();
        $merged = $facts->mergedWith(...[$argument => $value]);
        $expected = (array) $facts;
        $expected[$property] = $value;
        self::assertSame($expected, (array) $merged, 'This field only, the others kept.');
    }

    #[Test]
    public function it_should_keep_what_an_inventory_did_not_observe(): void
    {
        $facts = Fleet::facts();
        self::assertSame((array) $facts, (array) $facts->mergedWith(), 'Nothing observed: nothing changes.');
        self::assertSame((array) $facts, (array) $facts->mergedWith('', '', '', '', '', '', ''), 'An empty value is not an observation.');
        self::assertSame(Fleet::MACHINE, $facts->mergedWith(hostname: 'other')->machineIdHash, 'Never taken from an inventory.');
        $this->assertRefused(InvalidHostFacts::class, 'invalid_payload', static fn () => $facts->mergedWith(kernel: u('a')->repeat(256)->toString()));
    }

    #[Test]
    public function it_should_observe_facts_without_touching_the_contact_or_the_name(): void
    {
        $host = Fleet::host();
        $host->relabel(new HostLabel('Shop', []));
        $host->releaseEvents();
        $host->observe(hostname: 'YggWeb', osPrettyName: 'Debian GNU/Linux 13 (trixie)');
        self::assertSame(['YggWeb', 'Debian GNU/Linux 13 (trixie)', Fleet::facts()->kernel], [$host->facts()->hostname, $host->facts()->osPrettyName, $host->facts()->kernel]);
        self::assertSame('Shop', $host->name(), 'The display name of a human stays.');
        self::assertNull($host->lastSeenAt(), 'The polls tell the last contact.');
        self::assertSame([], $host->releaseEvents(), 'Nothing published: no loop with HostLabelled.');

        $before = (array) $host->facts();
        $this->assertRefused(InvalidHostFacts::class, 'invalid_payload', static fn () => $host->observe(hostname: "web\u{202E}"));
        self::assertSame($before, (array) $host->facts(), 'Refused: the facts stay as they were.');
    }

    #[Test]
    public function it_should_promote_a_host_known_by_its_hostname_only_to_its_docker_engine(): void
    {
        $host = Host::register(HostId::fromString(Fleet::HOST), Uuid::fromString(Fleet::TEAM), new HostIdentity('hostname', 'd0f0663a8c03'), new HostFacts('d0f0663a8c03'), Fleet::now());
        self::assertTrue($host->identity()->isHostname());
        self::assertNull($host->dockerIdentityCandidate(), 'No engine id observed yet.');
        self::assertFalse($host->promoteToDockerEngine());

        $host->observe(hostname: 'YggWeb', dockerEngineId: 'engine-1');
        self::assertEquals(HostIdentity::docker('engine-1'), $host->dockerIdentityCandidate());
        self::assertEquals(new HostFacts('YggWeb', dockerEngineId: 'engine-1')->identityFor(AgentMode::Container, 'ed25519:key'), $host->dockerIdentityCandidate(), 'The identity a later enrollment computes.');
        self::assertTrue($host->promoteToDockerEngine());
        self::assertEquals(new HostIdentity('docker', 'engine-1'), $host->identity());
        self::assertFalse($host->identity()->isHostname());
        self::assertNull($host->dockerIdentityCandidate(), 'Promoted once.');
        self::assertFalse($host->promoteToDockerEngine());

        $host->observe(dockerEngineId: 'engine-2');
        self::assertEquals(HostIdentity::docker('engine-1'), $host->identity(), 'A new engine id is only a fact.');
        self::assertSame([], $host->releaseEvents());
    }

    #[Test]
    public function it_should_never_promote_another_identity(): void
    {
        foreach (['machine', 'docker', 'embedded'] as $kind) {
            $host = Host::register(HostId::fromString(Fleet::HOST), Uuid::fromString(Fleet::TEAM), new HostIdentity($kind, 'value'), new HostFacts('web-01', dockerEngineId: 'engine-1'), Fleet::now());
            self::assertNull($host->dockerIdentityCandidate(), $kind);
            self::assertFalse($host->promoteToDockerEngine(), $kind);
            self::assertEquals(new HostIdentity($kind, 'value'), $host->identity(), $kind);
        }

        $empty = Host::register(HostId::fromString(Fleet::HOST), Uuid::fromString(Fleet::TEAM), new HostIdentity('hostname', 'web-01'), new HostFacts('web-01', dockerEngineId: ''), Fleet::now());
        self::assertNull($empty->dockerIdentityCandidate(), 'An empty engine id is no engine.');
        self::assertFalse($empty->promoteToDockerEngine());
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
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function invalidFacts(): iterable
    {
        $long = u('a')->repeat(256)->toString();
        yield 'blank hostname' => [static fn () => new HostFacts('  '), 'hostname'];
        yield 'long hostname' => [static fn () => new HostFacts($long), 'hostname'];
        yield 'long os id' => [static fn () => new HostFacts('h', osId: $long), 'os_id'];
        yield 'long os version' => [static fn () => new HostFacts('h', osVersionId: $long), 'os_version_id'];
        yield 'long pretty name' => [static fn () => new HostFacts('h', osPrettyName: $long), 'os_pretty_name'];
        yield 'long kernel' => [static fn () => new HostFacts('h', kernel: $long), 'kernel'];
        yield 'long arch' => [static fn () => new HostFacts('h', arch: $long), 'arch'];
        yield 'long engine' => [static fn () => new HostFacts('h', dockerEngineId: $long), 'docker_engine_id'];
        yield 'control character' => [static fn () => new HostFacts("web\u{0007}"), 'hostname'];
        yield 'bidi override' => [static fn () => new HostFacts("web\u{202E}10-bew"), 'hostname'];
        yield 'left-to-right mark' => [static fn () => new HostFacts('h', osPrettyName: "Debian\u{200E}"), 'os_pretty_name'];
        yield 'zero-width space' => [static fn () => new HostFacts('h', kernel: "6.1\u{200B}"), 'kernel'];
        yield 'byte order mark' => [static fn () => new HostFacts('h', arch: "\u{FEFF}x86_64"), 'arch'];
        yield 'format character in os id' => [static fn () => new HostFacts('h', osId: "deb\u{00AD}ian"), 'os_id'];
        yield 'format character in os version' => [static fn () => new HostFacts('h', osVersionId: "1\u{2066}2"), 'os_version_id'];
        yield 'format character in engine' => [static fn () => new HostFacts('h', dockerEngineId: "e\u{200D}"), 'docker_engine_id'];
        yield 'machine id not hex' => [static fn () => new HostFacts('h', machineIdHash: u('z')->repeat(64)->toString()), 'machine_id_hash'];
        yield 'machine id short' => [static fn () => new HostFacts('h', machineIdHash: u('a')->repeat(63)->toString()), 'machine_id_hash'];
        yield 'machine id uppercase' => [static fn () => new HostFacts('h', machineIdHash: u('A')->repeat(64)->toString()), 'machine_id_hash'];
    }

    /**
     * @param \Closure(): mixed $build
     */
    #[Test]
    #[DataProvider('invalidFacts')]
    public function it_should_refuse_facts_out_of_the_protocol_bounds(\Closure $build, string $field): void
    {
        try {
            $build();
            self::fail('Expected InvalidHostFacts.');
        } catch (InvalidHostFacts $refused) {
            self::assertSame(['invalid_payload', $field], [$refused->ruleKey(), $refused->field], 'The agent gets the usual answer; the logs get the field.');
        }
    }

    /**
     * @return iterable<string, array{\Closure(): mixed}>
     */
    public static function invalidRuntimes(): iterable
    {
        $long = u('a')->repeat(256)->toString();
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
    #[DataProvider('invalidRuntimes')]
    public function it_should_refuse_a_runtime_out_of_the_protocol_bounds(\Closure $build): void
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
        self::assertSame('Ubuntu 24.04 — édition « noble »', new HostFacts('h', osPrettyName: 'Ubuntu 24.04 — édition « noble »')->osPrettyName, 'Letters and punctuation of any script stay.');
    }
}
