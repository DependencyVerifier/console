<?php

declare(strict_types=1);

namespace Tests\Fleet\Unit\Application;

use Fleet\Application\Command\Agent\EnrollAgent;
use Fleet\Application\Protocol\Problem;
use Fleet\Domain\Model\AgentId;
use Fleet\Domain\Model\AgentMode;
use Fleet\Domain\Model\AgentScope;
use Fleet\Domain\Model\AgentStatus;
use Fleet\Domain\Model\EnrollmentTokenExpired;
use Fleet\Domain\Model\EnrollmentTokenInvalid;
use Fleet\Domain\Model\FleetQuotaReached;
use Fleet\Domain\Model\HostIdentity;
use Fleet\Domain\Model\InvalidEnrollmentPayload;
use Fleet\Domain\Model\InvalidHostFacts;
use Fleet\Domain\Model\RevokedAgent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Audit\Target;
use Shared\Application\Security\Throttled;
use Shared\Application\Security\ThrottleName;
use Shared\Domain\BusinessRuleViolated;
use Symfony\Component\Uid\Uuid;
use Tests\Fleet\Support\AgentClient;
use Tests\Fleet\Support\Fleet;
use Tests\Fleet\Support\FleetWorld;

use function Symfony\Component\String\u;

final class EnrollAgentTest extends TestCase
{
    private const string NEW_AGENT = '019a6b2c-8f00-7a11-8b22-3c44d55e6f09';
    private const string SECOND = '019a6b2c-8f00-7a11-8b22-3c44d55e6f0a';

    #[Test]
    public function it_should_enroll_a_pending_agent_on_a_new_host(): void
    {
        $world = $this->world();
        $agent = AgentClient::generate();
        $world->enroll()($this->command($world, $agent));

        $enrolled = $world->agents->find(AgentId::fromString(self::NEW_AGENT));
        self::assertNotNull($enrolled);
        self::assertSame([AgentStatus::Pending, $agent->key->fingerprint(), Fleet::TEAM, Fleet::TOKEN], [$enrolled->status(), $enrolled->fingerprint(), $enrolled->teamId()->toRfc4122(), $enrolled->enrollmentTokenId()->toString()]);
        self::assertEquals(Fleet::runtime(), $enrolled->runtime());
        self::assertSame(1, $world->tokens->find(Fleet::single()->id())?->uses());
        $host = $world->hosts->find($enrolled->hostId());
        self::assertNotNull($host);
        self::assertSame(['web-01', 'debian', '12', 'Debian 12', '6.1', 'x86_64', 'engine', null], [
            $host->facts()->hostname, $host->facts()->osId, $host->facts()->osVersionId, $host->facts()->osPrettyName, $host->facts()->kernel, $host->facts()->arch, $host->facts()->dockerEngineId, $host->facts()->machineIdHash,
        ]);
        self::assertEquals(new HostIdentity('docker', 'engine'), $host->identity());
        self::assertSame(['consume agent_enrollment_ip ip:203.0.113.7'], $world->throttle->calls);
        self::assertSame(['fleet.team:'.Fleet::TEAM], $world->lock->keys);
        self::assertSame(['h:dvet_plain', 'h:dvet_plain'], $world->tokens->hashLookups, 'Read again after the team lock.');
        self::assertCount(1, $world->nonces->seen);
    }

    #[Test]
    public function it_should_enroll_an_embedded_agent_as_its_own_host(): void
    {
        $world = $this->world();
        $agent = AgentClient::generate();
        $world->enroll()($this->command($world, $agent, body: ['mode' => 'embedded', 'scope' => null, 'compose_project' => null]));

        $enrolled = $world->agents->find(AgentId::fromString(self::NEW_AGENT));
        self::assertNotNull($enrolled);
        self::assertSame([AgentMode::Embedded, null, null], [$enrolled->runtime()->mode, $enrolled->runtime()->scope, $enrolled->runtime()->composeProject]);
        self::assertEquals(new HostIdentity('embedded', $agent->key->fingerprint()), $world->hosts->find($enrolled->hostId())?->identity(), 'One host per replica, whatever its engine.');

        $fresh = $this->world();
        $this->assertRefused(InvalidEnrollmentPayload::class, 'invalid_payload', fn () => $fresh->enroll()($this->command($fresh, AgentClient::generate(), body: ['mode' => 'embedded', 'compose_project' => null])));
    }

    #[Test]
    public function it_should_answer_a_retry_with_the_first_agent_and_refuse_a_revoked_one(): void
    {
        $world = $this->world();
        $agent = AgentClient::generate();
        $world->enroll()($this->command($world, $agent));
        $world->enroll()($this->command($world, $agent, self::SECOND));
        self::assertCount(1, $world->agents->agents);
        self::assertSame(1, $world->tokens->find(Fleet::single()->id())?->uses(), 'Not consumed twice.');

        $first = $world->agents->find(AgentId::fromString(self::NEW_AGENT));
        self::assertNotNull($first);
        $first->markInactiveIfSilent(Fleet::now());
        $first->approve(Uuid::v7(), Fleet::now());
        self::assertTrue($first->markInactiveIfSilent(Fleet::now()->modify('+8 days')));
        $world->enroll()($this->command($world, $agent, self::SECOND));
        self::assertCount(1, $world->agents->agents, 'An inactive agent retrying: still the first one.');

        $first->revoke(Fleet::now());
        $this->assertRefused(RevokedAgent::class, 'agent_revoked', fn () => $world->enroll()($this->command($world, $agent, self::SECOND)));
    }

    #[Test]
    public function it_should_reuse_the_host_of_the_same_machine_and_approve_with_an_approved_reusable_token(): void
    {
        $world = $this->world();
        $token = Fleet::reusable(id: '019a6b2c-6f00-7a11-8b22-3c44d55e6f31');
        $token->approve(Uuid::v7(), Fleet::now());
        $world->tokens->addWithHash($token, 'h:dvet_reusable');
        $world->enroll()($this->command($world, AgentClient::generate()));
        $world->enroll()($this->command($world, AgentClient::generate(), self::SECOND, ['enrollment_token' => 'dvet_reusable', 'host' => ['hostname' => 'web-01b', 'docker_engine_id' => 'engine']]));

        self::assertCount(1, $world->hosts->hosts, 'Same engine: same host.');
        self::assertSame('web-01b', array_first($world->hosts->hosts)->facts()->hostname, 'Facts of the last enrollment.');
        self::assertEquals(Fleet::now(), array_first($world->hosts->hosts)->lastSeenAt());
        self::assertSame(AgentStatus::Approved, $world->agents->find(AgentId::fromString(self::SECOND))?->status());
    }

    #[Test]
    public function it_should_enroll_a_host_agent_by_its_machine_id(): void
    {
        $world = $this->world();
        $world->enroll()($this->command($world, AgentClient::generate(), body: ['mode' => 'host', 'scope' => null, 'compose_project' => null, 'host' => ['hostname' => 'db-01', 'os' => null, 'machine_id_hash' => Fleet::MACHINE]]));

        $agent = $world->agents->find(AgentId::fromString(self::NEW_AGENT));
        self::assertNotNull($agent);
        self::assertSame([AgentMode::Host, null, null], [$agent->runtime()->mode, $agent->runtime()->scope, $agent->runtime()->composeProject]);
        self::assertEquals(new HostIdentity('machine', Fleet::MACHINE), $world->hosts->find($agent->hostId())?->identity());
        self::assertNull($world->hosts->find($agent->hostId())?->facts()->osId);
    }

    #[Test]
    public function it_should_enroll_a_labeled_container_agent(): void
    {
        $world = $this->world();
        $world->enroll()($this->command($world, AgentClient::generate(), body: ['scope' => 'labeled']));
        self::assertSame(AgentScope::Labeled, $world->agents->find(AgentId::fromString(self::NEW_AGENT))?->runtime()->scope);
    }

    #[Test]
    public function it_should_refuse_the_last_seat_and_keep_the_token_usable(): void
    {
        $world = $this->world();
        $world->limits['max_agents'] = 1;
        $world->agents->add(Fleet::agent(id: '019a6b2c-8f00-7a11-8b22-3c44d55e6f99'));

        $this->assertRefused(FleetQuotaReached::class, 'quota', fn () => $world->enroll()($this->command($world, AgentClient::generate())));
        self::assertCount(1, $world->agents->agents);
        $world->limits['max_agents'] = 2;
        $world->enroll()($this->command($world, AgentClient::generate()));
        self::assertCount(2, $world->agents->agents);
    }

    #[Test]
    public function it_should_refuse_an_unknown_used_or_expired_token(): void
    {
        $world = $this->world();
        $this->assertRefused(EnrollmentTokenInvalid::class, 'token_invalid', fn () => $world->enroll()($this->command($world, AgentClient::generate(), body: ['enrollment_token' => 'dvet_unknown'])));
        self::assertSame([], $world->lock->keys, 'No lock for an unknown token.');

        $world->enroll()($this->command($world, AgentClient::generate()));
        $this->assertRefused(EnrollmentTokenInvalid::class, 'token_invalid', fn () => $world->enroll()($this->command($world, AgentClient::generate(), self::SECOND)));

        $expired = $this->world();
        $expired->clock->modify('+1 hour');
        $this->assertRefused(EnrollmentTokenExpired::class, 'token_expired', fn () => $expired->enroll()($this->command($expired, AgentClient::generate())));
    }

    #[Test]
    public function it_should_refuse_a_key_already_enrolled_with_another_token(): void
    {
        $world = $this->world();
        $agent = AgentClient::generate();
        $world->enroll()($this->command($world, $agent));
        $other = Fleet::single(id: '019a6b2c-6f00-7a11-8b22-3c44d55e6f32');
        $world->tokens->addWithHash($other, 'h:dvet_other');

        $this->assertProblem(Problem::InvalidPayload, fn () => $world->enroll()($this->command($world, $agent, self::SECOND, ['enrollment_token' => 'dvet_other'])));
        self::assertSame(0, $other->uses());
    }

    #[Test]
    public function it_should_throttle_by_ip_before_anything_else(): void
    {
        $world = $this->world();
        $world->throttle->refuse(ThrottleName::AgentEnrollmentIp);
        try {
            $world->enroll()($this->command($world, AgentClient::generate()));
            self::fail('Throttled.');
        } catch (Throttled $throttled) {
            self::assertEquals($world->throttle->retryAfter, $throttled->retryAfter);
        }

        self::assertSame([], $world->nonces->seen);
        $noIp = $this->world(null);
        $noIp->enroll()($this->command($noIp, AgentClient::generate()));
        self::assertSame([], $noIp->throttle->calls, 'No IP (CLI): no IP limiter.');
    }

    #[Test]
    public function it_should_check_the_proof_of_possession_with_the_key_of_the_body(): void
    {
        $world = $this->world();
        $agent = AgentClient::generate();
        $request = $agent->sign('https://localhost/agent/v1/enrollments', $this->body() + ['public_key' => AgentClient::generate()->key->text()], $world->now());
        $this->assertProblem(Problem::UnknownAgent, static fn () => $world->enroll()(new EnrollAgent(self::NEW_AGENT, $request)));
        self::assertSame([], $world->tokens->hashLookups, 'The token is looked at only once the signature holds.');

        $this->assertProblem(Problem::InvalidPayload, fn () => $world->enroll()($this->command($world, $agent, body: ['public_key' => 'ed25519:short'])));
        $this->assertProblem(Problem::InvalidPayload, fn () => $world->enroll()($this->command($world, $agent, body: ['public_key' => null])));
        $this->assertProblem(Problem::UnsupportedProtocolVersion, fn () => $world->enroll()($this->command($world, $agent, body: ['protocol_version' => 2])));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'ecs mode' => [['mode' => 'ecs']];
        yield 'unknown scope' => [['scope' => 'pod']];
        yield 'no token' => [['enrollment_token' => null]];
        yield 'no version' => [['agent_version' => null]];
        yield 'no host' => [['host' => null]];
        yield 'host without hostname' => [['host' => ['arch' => 'x86_64']]];
        yield 'os as a string' => [['host' => ['hostname' => 'h', 'os' => 'debian']]];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('invalidPayloads')]
    public function it_should_refuse_an_invalid_payload_before_using_the_token(array $body): void
    {
        $world = $this->world();
        $this->assertProblem(Problem::InvalidPayload, fn () => $world->enroll()($this->command($world, AgentClient::generate(), body: $body)));
        self::assertSame(0, $world->tokens->find(Fleet::single()->id())?->uses());
    }

    #[Test]
    public function it_should_refuse_facts_out_of_bounds(): void
    {
        $world = $this->world();
        $this->assertRefused(InvalidHostFacts::class, 'invalid_payload', fn () => $world->enroll()($this->command($world, AgentClient::generate(), body: ['host' => ['hostname' => u('a')->repeat(256)->toString()]])));
        $this->assertRefused(InvalidHostFacts::class, 'invalid_payload', fn () => $world->enroll()($this->command($world, AgentClient::generate(), body: ['host' => ['hostname' => "web-01\u{202E}"]])));
        $this->assertRefused(InvalidEnrollmentPayload::class, 'invalid_payload', fn () => $world->enroll()($this->command($world, AgentClient::generate(), body: ['compose_project' => null])));
    }

    #[Test]
    public function it_should_audit_the_enrollment_on_the_new_agent(): void
    {
        $command = new EnrollAgent(self::NEW_AGENT, AgentClient::generate()->signBodyless('https://localhost/', 0));
        self::assertSame('fleet.enroll_agent', $command->auditAction()->name);
        self::assertEquals(Target::of('agent', self::NEW_AGENT), $command->auditTarget());
        self::assertSame([], $command->auditContext()->values);
    }

    private function world(?string $ip = '203.0.113.7'): FleetWorld
    {
        $world = new FleetWorld($ip);
        $world->tokens->addWithHash(Fleet::single(), 'h:dvet_plain');

        return $world;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(): array
    {
        return [
            'protocol_version' => 1,
            'enrollment_token' => 'dvet_plain',
            'agent_version' => '0.1.0',
            'mode' => 'container',
            'scope' => 'compose',
            'compose_project' => 'shop',
            'host' => ['hostname' => 'web-01', 'os' => ['id' => 'debian', 'version_id' => '12', 'pretty_name' => 'Debian 12'], 'kernel' => '6.1', 'arch' => 'x86_64', 'docker_engine_id' => 'engine'],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function command(FleetWorld $world, AgentClient $agent, string $agentId = self::NEW_AGENT, array $body = []): EnrollAgent
    {
        $payload = array_filter($body + $this->body() + ['public_key' => $agent->key->text()], static fn (mixed $value): bool => null !== $value);

        return new EnrollAgent($agentId, $agent->sign('https://localhost/agent/v1/enrollments', $payload, $world->now()));
    }

    /**
     * @param class-string<BusinessRuleViolated> $class
     * @param \Closure(): mixed                  $gesture
     */
    private function assertRefused(string $class, string $ruleKey, \Closure $gesture): void
    {
        try {
            $gesture();
            self::fail('Expected '.$class);
        } catch (BusinessRuleViolated $refused) {
            self::assertInstanceOf($class, $refused, $refused->getMessage());
            self::assertSame($ruleKey, $refused->ruleKey());
        }
    }

    /**
     * @param \Closure(): mixed $gesture
     */
    private function assertProblem(Problem $problem, \Closure $gesture): void
    {
        try {
            $gesture();
            self::fail('Expected '.$problem->value);
        } catch (BusinessRuleViolated $refusal) {
            self::assertSame($problem->value, $refusal->ruleKey(), $refusal->getMessage());
        }
    }
}
