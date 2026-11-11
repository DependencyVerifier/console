<?php

declare(strict_types=1);

namespace Inventory\Infrastructure\Doctrine;

use Inventory\Domain\Model\HostInventory;
use Inventory\Domain\Repository\HostInventoryRepository;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineHostInventoryRepository implements HostInventoryRepository
{
    public function __construct(private InventoryManager $manager)
    {
    }

    public function find(Uuid $hostId): ?HostInventory
    {
        return $this->manager->get()->find(HostInventory::class, $hostId);
    }

    public function add(HostInventory $host): void
    {
        $this->manager->get()->persist($host);
    }

    public function deleteOfTeam(Uuid $teamId): void
    {
        $this->manager->get()->createQueryBuilder()->delete(HostInventory::class, 'h')->where('h.teamId = :team')
            ->setParameter('team', $teamId, 'uuid')->getQuery()->execute();
    }
}
