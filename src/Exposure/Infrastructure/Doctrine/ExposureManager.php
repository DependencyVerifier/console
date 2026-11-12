<?php

declare(strict_types=1);

namespace Exposure\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Exposure\Domain\Model\Finding;

/**
 * Through ManagerRegistry, never an EntityManager injected once: the bus may reset a closed manager.
 */
final readonly class ExposureManager
{
    public function __construct(private ManagerRegistry $registry)
    {
    }

    public function get(): EntityManagerInterface
    {
        $manager = $this->registry->getManagerForClass(Finding::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('No entity manager for Exposure.');
        }

        return $manager;
    }
}
