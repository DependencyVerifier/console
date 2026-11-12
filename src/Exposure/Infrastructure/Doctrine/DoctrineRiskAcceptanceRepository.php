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

    public function deleteTeam(Uuid $teamId): void
    {
        $this->manager->get()->createQueryBuilder()->delete(RiskAcceptance::class, 'r')->where('r.teamId = :team')->setParameter('team', $teamId, 'uuid')->getQuery()->execute();
    }
}
