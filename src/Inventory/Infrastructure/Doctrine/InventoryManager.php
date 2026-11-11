<?php

declare(strict_types=1);

namespace Inventory\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Inventory\Domain\Model\InventoryRun;

/**
 * Through ManagerRegistry, never an EntityManager injected once: the bus may reset a closed manager.
 */
final readonly class InventoryManager
{
    public function __construct(private ManagerRegistry $registry)
    {
    }

    public function get(): EntityManagerInterface
    {
        $manager = $this->registry->getManagerForClass(InventoryRun::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('No entity manager for Inventory.');
        }

        return $manager;
    }
}
