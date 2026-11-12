<?php

declare(strict_types=1);

namespace Exposure\Infrastructure\Doctrine;

use Exposure\Domain\Model\TeamExposure;
use Exposure\Domain\Repository\TeamExposureRepository;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineTeamExposureRepository implements TeamExposureRepository
{
    public function __construct(private ExposureManager $manager)
    {
    }

    public function find(Uuid $teamId): ?TeamExposure
    {
        return $this->manager->get()->find(TeamExposure::class, $teamId);
    }

    public function add(TeamExposure $exposure): void
    {
        $this->manager->get()->persist($exposure);
    }

    public function deleteTeam(Uuid $teamId): void
    {
        $this->manager->get()->createQueryBuilder()->delete(TeamExposure::class, 't')->where('t.teamId = :team')->setParameter('team', $teamId, 'uuid')->getQuery()->execute();
    }
}
