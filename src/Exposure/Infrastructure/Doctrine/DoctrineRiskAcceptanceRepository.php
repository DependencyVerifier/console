<?php

declare(strict_types=1);

namespace Exposure\Infrastructure\Doctrine;

use Exposure\Domain\Model\RiskAcceptance;
use Exposure\Domain\Repository\RiskAcceptanceRepository;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineRiskAcceptanceRepository implements RiskAcceptanceRepository
{
    public function __construct(private ExposureManager $manager)
    {
    }

    public function ofFinding(Uuid $findingId): array
    {
        return $this->manager->get()->getRepository(RiskAcceptance::class)->findBy(['findingId' => $findingId], ['acceptedAt' => 'DESC', 'id' => 'DESC']);
    }

    public function add(RiskAcceptance $acceptance): void
    {
        $this->manager->get()->persist($acceptance);
    }

    public function expiringBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<RiskAcceptance> */
        return $this->manager->get()->createQueryBuilder()->select('r')->from(RiskAcceptance::class, 'r')
            ->where('r.revokedAt IS NULL')->andWhere('r.expiresOn BETWEEN :from AND :to')
            ->setParameter('from', $from->format('Y-m-d'))->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('r.expiresOn')->addOrderBy('r.id')->getQuery()->getResult();
    }

    public function deleteTeam(Uuid $teamId): void
    {
        $this->manager->get()->createQueryBuilder()->delete(RiskAcceptance::class, 'r')->where('r.teamId = :team')->setParameter('team', $teamId, 'uuid')->getQuery()->execute();
    }
}
