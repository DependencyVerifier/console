<?php

declare(strict_types=1);

namespace Exposure\Infrastructure\Doctrine;

use Exposure\Domain\Model\Finding;
use Exposure\Domain\Repository\FindingRepository;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineFindingRepository implements FindingRepository
{
    public function __construct(private ExposureManager $manager)
    {
    }

    public function ofTeam(Uuid $teamId): array
    {
        return $this->manager->get()->getRepository(Finding::class)->findBy(['teamId' => $teamId]);
    }

    public function find(Uuid $findingId): ?Finding
    {
        return $this->manager->get()->find(Finding::class, $findingId);
    }

    public function add(Finding $finding): void
    {
        $this->manager->get()->persist($finding);
    }

    public function deleteTeam(Uuid $teamId): void
    {
        $this->manager->get()->createQueryBuilder()->delete(Finding::class, 'f')->where('f.teamId = :team')->setParameter('team', $teamId, 'uuid')->getQuery()->execute();
    }
}
