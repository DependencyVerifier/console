<?php

declare(strict_types=1);

namespace Fleet\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Fleet\Domain\Model\Agent;

/**
 * Through ManagerRegistry, never an EntityManager injected once: the bus may reset a closed manager.
 */
final readonly class FleetManager
{
    public function __construct(private ManagerRegistry $registry)
    {
    }

    public function get(): EntityManagerInterface
    {
        $manager = $this->registry->getManagerForClass(Agent::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('No entity manager for Fleet.');
        }

        return $manager;
    }
}
