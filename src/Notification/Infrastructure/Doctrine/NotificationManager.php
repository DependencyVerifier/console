<?php

declare(strict_types=1);

namespace Notification\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Notification\Domain\Model\NotificationRule;

/**
 * Through ManagerRegistry, never an EntityManager injected once: the bus may reset a closed manager.
 */
final readonly class NotificationManager
{
    public function __construct(private ManagerRegistry $registry)
    {
    }

    public function get(): EntityManagerInterface
    {
        $manager = $this->registry->getManagerForClass(NotificationRule::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('No entity manager for Notification.');
        }

        return $manager;
    }
}
