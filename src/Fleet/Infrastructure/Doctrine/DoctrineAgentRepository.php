<?php

declare(strict_types=1);

namespace Fleet\Infrastructure\Doctrine;

use Fleet\Domain\Model\Agent;
use Fleet\Domain\Model\AgentId;
use Fleet\Domain\Model\AgentStatus;
use Fleet\Domain\Model\EnrollmentTokenId;
use Fleet\Domain\Model\HostId;
use Fleet\Domain\Repository\AgentRepository;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineAgentRepository implements AgentRepository
{
    public function __construct(private FleetManager $manager)
    {
    }

    public function find(AgentId $id): ?Agent
    {
        return $this->manager->get()->find(Agent::class, $id->value);
    }

    public function findLiveByFingerprint(string $fingerprint): ?Agent
    {
        /** @var ?Agent */
        return $this->manager->get()->createQueryBuilder()
            ->select('a')->from(Agent::class, 'a')
            ->where('a.fingerprint = :fingerprint')->andWhere('a.status <> :revoked')
            ->setParameter('fingerprint', $fingerprint)->setParameter('revoked', AgentStatus::Revoked)
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    public function findByFingerprint(string $fingerprint): ?Agent
    {
        return $this->findLiveByFingerprint($fingerprint)
            ?? $this->manager->get()->getRepository(Agent::class)->findOneBy(['fingerprint' => $fingerprint], ['enrolledAt' => 'DESC']);
    }

    public function findEnrolled(EnrollmentTokenId $tokenId, string $fingerprint): ?Agent
    {
        return $this->manager->get()->getRepository(Agent::class)->findOneBy(['enrollmentTokenId' => $tokenId->value, 'fingerprint' => $fingerprint]);
    }

    public function ofToken(EnrollmentTokenId $tokenId): array
    {
        /** @var list<Agent> */
        return $this->manager->get()->getRepository(Agent::class)->findBy(['enrollmentTokenId' => $tokenId->value], ['enrolledAt' => 'ASC']);
    }

    public function add(Agent $agent): void
    {
        $this->manager->get()->persist($agent);
    }

    public function remove(Agent $agent): void
    {
        $this->manager->get()->remove($agent);
    }

    public function countInQuota(Uuid $teamId): int
    {
        return (int) $this->manager->get()->createQueryBuilder()
            ->select('COUNT(a.id)')->from(Agent::class, 'a')
            ->where('a.teamId = :team')->andWhere('a.status IN (:counted)')
            ->setParameter('team', $teamId, 'uuid')->setParameter('counted', [AgentStatus::Pending->value, AgentStatus::Approved->value])
            ->getQuery()->getSingleScalarResult();
    }

    public function ofHost(HostId $hostId): array
    {
        /** @var list<Agent> */
        return $this->manager->get()->getRepository(Agent::class)->findBy(['hostId' => $hostId->value], ['enrolledAt' => 'ASC']);
    }

    public function approvedSilentSince(\DateTimeImmutable $before, int $limit): array
    {
        /** @var list<Agent> */
        return $this->manager->get()->createQueryBuilder()
            ->select('a')->from(Agent::class, 'a')
            ->where('a.status = :approved')->andWhere('a.lastSeenAt <= :before')
            ->setParameter('approved', AgentStatus::Approved)->setParameter('before', $before)
            ->orderBy('a.lastSeenAt')->setMaxResults($limit)->getQuery()->getResult();
    }

    public function deleteOfTeam(Uuid $teamId): void
    {
        $this->manager->get()->createQueryBuilder()->delete(Agent::class, 'x')->where('x.teamId = :team')
            ->setParameter('team', $teamId, 'uuid')->getQuery()->execute();
    }
}
