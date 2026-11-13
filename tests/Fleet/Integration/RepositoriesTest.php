<?php

declare(strict_types=1);

namespace Tests\Fleet\Integration;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Fleet\Domain\Model\Agent;
use Fleet\Domain\Model\AgentConfig;
use Fleet\Domain\Model\AgentId;
use Fleet\Domain\Model\AgentMode;
use Fleet\Domain\Model\AgentOrder;
use Fleet\Domain\Model\AgentOrderId;
use Fleet\Domain\Model\AgentPublicKey;
use Fleet\Domain\Model\EnrollmentTokenId;
use Fleet\Domain\Model\HostFacts;
use Fleet\Domain\Model\HostId;
use Fleet\Domain\Model\HostIdentity;
use Fleet\Domain\Model\OrderType;
use Fleet\Infrastructure\Doctrine\DoctrineAgentOrderRepository;
use Fleet\Infrastructure\Doctrine\DoctrineAgentRepository;
use Fleet\Infrastructure\Doctrine\DoctrineEnrollmentTokenRepository;
use Fleet\Infrastructure\Doctrine\DoctrineHostRepository;
use Fleet\Infrastructure\Doctrine\FleetManager;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Tests\Fleet\Support\Fleet;

/**
 * The Fleet tables against PostgreSQL: quota counts, lookups, and the partial unique index that keeps one live agent
 * per key.
 */
final class RepositoriesTest extends KernelTestCase
{
    private FleetManager $manager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->manager = new FleetManager(self::getContainer()->get('doctrine'));
    }

    #[Test]
    public function it_should_count_the_active_reusable_tokens_and_find_one_by_hash(): void
    {
        $tokens = new DoctrineEnrollmentTokenRepository($this->manager);
        $now = Fleet::now();
        $active = Fleet::reusable(maxUses: 1, id: '019a6b2c-6f00-7a11-8b22-3c44d55e6f11');
        $usedUp = Fleet::reusable(maxUses: 1, id: '019a6b2c-6f00-7a11-8b22-3c44d55e6f12');
        $usedUp->consume($now);
        $revoked = Fleet::reusable(id: '019a6b2c-6f00-7a11-8b22-3c44d55e6f13');
        $revoked->revoke($now);
        foreach ([$active, $usedUp, $revoked, Fleet::single(id: '019a6b2c-6f00-7a11-8b22-3c44d55e6f14')] as $token) {
            $tokens->add($token);
        }
        $this->flush();

        self::assertSame(1, $tokens->countActiveReusable(Uuid::fromString(Fleet::TEAM), $now));
        self::assertSame(0, $tokens->countActiveReusable(Uuid::fromString(Fleet::TEAM), $now->modify('+30 days')), 'Expired.');
        self::assertSame(0, $tokens->countActiveReusable(Uuid::fromString(Fleet::OTHER_TEAM), $now));
        self::assertTrue($tokens->hasActiveForEnvironment(Uuid::fromString(Fleet::ENVIRONMENT), $now));
        self::assertFalse($tokens->hasActiveForEnvironment(Uuid::fromString(Fleet::ENVIRONMENT), $now->modify('+30 days')));
        self::assertFalse($tokens->hasActiveForEnvironment(Uuid::fromString(Fleet::TEAM), $now));
        self::assertSame('019a6b2c-6f00-7a11-8b22-3c44d55e6f12', $tokens->findByHash('hash-019a6b2c-6f00-7a11-8b22-3c44d55e6f12')?->id()->toString());
        self::assertSame(1, $tokens->find(EnrollmentTokenId::fromString('019a6b2c-6f00-7a11-8b22-3c44d55e6f12'))?->uses());
        self::assertNull($tokens->findByHash('unknown'));
    }

    #[Test]
    public function it_should_find_a_host_by_its_identity_within_its_team(): void
    {
        $hosts = new DoctrineHostRepository($this->manager);
        $hosts->add(Fleet::host());
        $this->flush();

        $identity = Fleet::facts()->identityFor(AgentMode::Container);
        self::assertSame(Fleet::HOST, $hosts->findByIdentity(Uuid::fromString(Fleet::TEAM), $identity)?->id()->toString());
        self::assertNull($hosts->findByIdentity(Uuid::fromString(Fleet::OTHER_TEAM), $identity));
        self::assertNull($hosts->findByIdentity(Uuid::fromString(Fleet::TEAM), new HostIdentity('machine', $identity->value)));
        $host = $hosts->find(HostId::fromString(Fleet::HOST));
        self::assertNotNull($host);
        self::assertSame('web-01', $host->facts()->hostname);
        $hosts->remove($host);
        $this->flush();
        self::assertNull($hosts->find(HostId::fromString(Fleet::HOST)));
    }

    #[Test]
    public function it_should_count_pending_and_approved_agents_and_keep_one_live_agent_per_key(): void
    {
        $agents = new DoctrineAgentRepository($this->manager);
        $tokens = new DoctrineEnrollmentTokenRepository($this->manager);
        $token = Fleet::reusable();
        $tokens->add($token);
        $pending = Fleet::agent($token);
        $agents->add($pending);
        $this->flush();

        self::assertSame(1, $agents->countInQuota(Uuid::fromString(Fleet::TEAM)));
        self::assertSame(Fleet::AGENT, $agents->findLiveByFingerprint(Fleet::FINGERPRINT)?->id()->toString());
        self::assertSame(Fleet::AGENT, $agents->findEnrolled($token->id(), Fleet::FINGERPRINT)?->id()->toString());
        self::assertNull($agents->findEnrolled(EnrollmentTokenId::fromString(Fleet::ORDER), Fleet::FINGERPRINT));
        self::assertCount(1, $agents->ofHost(HostId::fromString(Fleet::HOST)));

        ($agents->find($pending->id()) ?? self::fail('No agent.'))->revoke(Fleet::now());
        $this->flush();
        self::assertSame(0, $agents->countInQuota(Uuid::fromString(Fleet::TEAM)));
        self::assertNull($agents->findLiveByFingerprint(Fleet::FINGERPRINT));

        // A new installation may enroll the same key with another token once the first agent is revoked…
        $second = Fleet::reusable(id: '019a6b2c-6f00-7a11-8b22-3c44d55e6f21');
        $tokens->add($second);
        $agents->add(Agent::enroll(AgentId::fromString('019a6b2c-8f00-7a11-8b22-3c44d55e6f02'), $second, Fleet::host(), AgentPublicKey::fromText(Fleet::KEY), Fleet::runtime(), Fleet::now()));
        $this->flush();
        self::assertSame('019a6b2c-8f00-7a11-8b22-3c44d55e6f02', $agents->findLiveByFingerprint(Fleet::FINGERPRINT)?->id()->toString());

        // …but never two live agents with one key.
        $third = Fleet::reusable(id: '019a6b2c-6f00-7a11-8b22-3c44d55e6f22');
        $tokens->add($third);
        $agents->add(Agent::enroll(AgentId::fromString('019a6b2c-8f00-7a11-8b22-3c44d55e6f03'), $third, Fleet::host(), AgentPublicKey::fromText(Fleet::KEY), Fleet::runtime(), Fleet::now()));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->flush();
    }

    #[Test]
    public function it_should_find_the_silent_agents_and_the_open_orders(): void
    {
        $agents = new DoctrineAgentRepository($this->manager);
        $orders = new DoctrineAgentOrderRepository($this->manager);
        $agent = Fleet::approved();
        $agents->add($agent);
        $scan = AgentOrder::scanNow(AgentOrderId::fromString(Fleet::ORDER), $agent, null, Fleet::now());
        $config = AgentOrder::setConfig(AgentOrderId::fromString('019a6b2c-9f00-7a11-8b22-3c44d55e6f02'), $agent, new AgentConfig(60), null, Fleet::now()->modify('+1 second'));
        $orders->add($scan);
        $orders->add($config);
        $this->flush();

        self::assertCount(0, $agents->approvedSilentSince(Fleet::now()->modify('-1 second'), 10));
        self::assertCount(1, $agents->approvedSilentSince(Fleet::now(), 10));
        self::assertSame([Fleet::ORDER, '019a6b2c-9f00-7a11-8b22-3c44d55e6f02'], array_map(static fn (AgentOrder $o): string => $o->id()->toString(), $orders->openOf($agent->id(), 20)));
        self::assertCount(1, $orders->openOf($agent->id(), 1));
        self::assertSame('019a6b2c-9f00-7a11-8b22-3c44d55e6f02', $orders->lastOf($agent->id(), OrderType::SetConfig)?->id()->toString());
        self::assertSame(Fleet::ORDER, $orders->lastOf($agent->id(), OrderType::ScanNow)?->id()->toString());

        $scan = $orders->find($scan->id()) ?? self::fail('No order.');
        $scan->issue(Fleet::now());
        $scan->deliver('signature', 'SHA256:console');
        $this->flush();
        self::assertCount(0, $orders->deliveredExpiredBefore(Fleet::now()->modify('+15 minutes -1 second'), 10));
        self::assertSame([Fleet::ORDER], array_map(static fn (AgentOrder $o): string => $o->id()->toString(), $orders->deliveredExpiredBefore(Fleet::now()->modify('+15 minutes'), 10)));
        self::assertSame('signature', $orders->find(AgentOrderId::fromString(Fleet::ORDER))?->signature());
        $other = Fleet::host(Fleet::OTHER_TEAM, '019a6b2c-7f00-7a11-8b22-3c44d55e6f02');
        new DoctrineHostRepository($this->manager)->add($other);
        $this->flush();
        foreach ([$orders, $agents, new DoctrineHostRepository($this->manager), new DoctrineEnrollmentTokenRepository($this->manager)] as $repository) {
            $repository->deleteOfTeam(Uuid::fromString(Fleet::TEAM));
        }

        self::assertNull($orders->find(AgentOrderId::fromString(Fleet::ORDER)));
        self::assertNull($agents->find($agent->id()));
        self::assertNotNull(new DoctrineHostRepository($this->manager)->find($other->id()), 'Another team keeps its fleet.');
        self::assertEquals(new HostFacts('web-01', 'debian', '12', 'Debian GNU/Linux 12 (bookworm)', '6.1.0-27-amd64', 'x86_64', 'b1c7a0f2-3e4d-4c5b-9a8f-7e6d5c4b3a21', Fleet::MACHINE), Fleet::facts());
    }

    private function flush(): void
    {
        $manager = $this->manager->get();
        $manager->flush();
        $manager->clear();
    }
}
