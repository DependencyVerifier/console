<?php

declare(strict_types=1);

namespace Tests\Fleet\Unit\Domain;

use Fleet\Domain\Model\AgentMode;
use Fleet\Domain\Model\AgentRuntime;
use Fleet\Domain\Model\AgentScope;
use Fleet\Domain\Model\HostFacts;
use Fleet\Domain\Model\HostIdentity;
use Fleet\Domain\Model\InvalidEnrollmentPayload;
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
        self::assertEquals(new HostIdentity('docker', 'b1c7a0f2-3e4d-4c5b-9a8f-7e6d5c4b3a21'), $facts->identityFor(AgentMode::Container));
        self::assertEquals(new HostIdentity('machine', Fleet::MACHINE), $facts->identityFor(AgentMode::Host));

        $noDocker = new HostFacts('web-01', machineIdHash: Fleet::MACHINE);
        self::assertEquals(new HostIdentity('machine', Fleet::MACHINE), $noDocker->identityFor(AgentMode::Container));
        $noMachine = new HostFacts('web-01', dockerEngineId: 'engine');
        self::assertEquals(new HostIdentity('docker', 'engine'), $noMachine->identityFor(AgentMode::Host));
        $empty = new HostFacts('Web-01', dockerEngineId: '');
        self::assertEquals(new HostIdentity('hostname', 'web-01'), $empty->identityFor(AgentMode::Container));
        self::assertEquals(new HostIdentity('hostname', 'web-01'), $empty->identityFor(AgentMode::Host));
    }

    #[Test]
    public function it_should_keep_the_facts_of_the_last_enrollment(): void
    {
        $host = Fleet::host();
        self::assertSame([Fleet::HOST, Fleet::TEAM], [$host->id()->toString(), $host->teamId()->toRfc4122()]);
        self::assertEquals(Fleet::facts()->identityFor(AgentMode::Container), $host->identity());
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
