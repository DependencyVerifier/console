<?php

declare(strict_types=1);

namespace Fleet\Infrastructure\Doctrine;

use Fleet\Domain\Model\EnrollmentToken;
use Fleet\Domain\Model\EnrollmentTokenId;
use Fleet\Domain\Model\EnrollmentTokenKind;
use Fleet\Domain\Model\EnrollmentTokenStatus;
use Fleet\Domain\Repository\EnrollmentTokenRepository;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineEnrollmentTokenRepository implements EnrollmentTokenRepository
{
    public function __construct(private FleetManager $manager)
    {
    }

    public function find(EnrollmentTokenId $id): ?EnrollmentToken
    {
        return $this->manager->get()->find(EnrollmentToken::class, $id->value);
    }

    public function findByHash(string $tokenHash): ?EnrollmentToken
    {
        return $this->manager->get()->getRepository(EnrollmentToken::class)->findOneBy(['tokenHash' => $tokenHash]);
    }

    public function add(EnrollmentToken $token): void
    {
        $this->manager->get()->persist($token);
    }

    public function countActiveReusable(Uuid $teamId, \DateTimeImmutable $now): int
    {
        return (int) $this->manager->get()->createQueryBuilder()
            ->select('COUNT(t.id)')->from(EnrollmentToken::class, 't')
            ->where('t.teamId = :team')->andWhere('t.kind = :kind')->andWhere('t.status <> :revoked')
            ->andWhere('t.expiresAt > :now')->andWhere('t.uses < t.maxUses')
            ->setParameter('team', $teamId, 'uuid')->setParameter('kind', EnrollmentTokenKind::Reusable)
            ->setParameter('revoked', EnrollmentTokenStatus::Revoked)->setParameter('now', $now)
            ->getQuery()->getSingleScalarResult();
    }

    public function hasActiveForEnvironment(Uuid $environmentId, \DateTimeImmutable $now): bool
    {
        return null !== $this->manager->get()->createQueryBuilder()
            ->select('t.id')->from(EnrollmentToken::class, 't')
            ->where('t.environmentId = :environment')->andWhere('t.status <> :revoked')
            ->andWhere('t.expiresAt > :now')->andWhere('t.uses < t.maxUses')
            ->setParameter('environment', $environmentId, 'uuid')
            ->setParameter('revoked', EnrollmentTokenStatus::Revoked)->setParameter('now', $now)
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }
}
