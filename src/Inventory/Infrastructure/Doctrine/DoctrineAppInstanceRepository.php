<?php

declare(strict_types=1);

namespace Inventory\Infrastructure\Doctrine;

use Inventory\Domain\Model\AppInstance;
use Inventory\Domain\Repository\AppInstanceRepository;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineAppInstanceRepository implements AppInstanceRepository
{
    public function __construct(private InventoryManager $manager)
    {
    }

    public function find(Uuid $id): ?AppInstance
    {
        return $this->manager->get()->find(AppInstance::class, $id);
    }

    public function ofHost(Uuid $hostId): array
    {
        /** @var list<AppInstance> */
        return $this->manager->get()->getRepository(AppInstance::class)->findBy(['hostId' => $hostId], ['firstSeenAt' => 'ASC', 'id' => 'ASC']);
    }

    public function add(AppInstance $app): void
    {
        $this->manager->get()->persist($app);
    }

    public function countInEnvironment(Uuid $environmentId): int
    {
        return (int) $this->manager->get()->createQueryBuilder()
            ->select('COUNT(a.id)')->from(AppInstance::class, 'a')->where('a.environmentId = :environment')
            ->setParameter('environment', $environmentId, 'uuid')->getQuery()->getSingleScalarResult();
    }

    public function deleteOfTeam(Uuid $teamId): void
    {
        $this->manager->get()->createQueryBuilder()->delete(AppInstance::class, 'a')->where('a.teamId = :team')
            ->setParameter('team', $teamId, 'uuid')->getQuery()->execute();
    }
}
