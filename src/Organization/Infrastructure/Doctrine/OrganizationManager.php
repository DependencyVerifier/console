<?php

declare(strict_types=1);

namespace Organization\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Organization\Domain\Model\Team;

/**
 * Through ManagerRegistry, never an EntityManager injected once: the bus may reset a closed manager.
 */
final readonly class OrganizationManager
{
    public function __construct(private ManagerRegistry $registry)
    {
    }

    public function get(): EntityManagerInterface
    {
        $manager = $this->registry->getManagerForClass(Team::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('No entity manager for Organization.');
        }

        return $manager;
    }
}
