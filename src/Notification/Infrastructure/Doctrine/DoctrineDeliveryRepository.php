<?php

declare(strict_types=1);

namespace Notification\Infrastructure\Doctrine;

use Notification\Domain\Model\Delivery;
use Notification\Domain\Model\DeliveryStatus;
use Notification\Domain\Model\Frequency;
use Notification\Domain\Repository\DeliveryRepository;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineDeliveryRepository implements DeliveryRepository
{
    public function __construct(private NotificationManager $manager)
    {
    }

    public function find(Uuid $id): ?Delivery
    {
        return $this->manager->get()->find(Delivery::class, $id);
    }

    public function add(Delivery $delivery): void
    {
        $this->manager->get()->persist($delivery);
    }

    public function due(\DateTimeImmutable $now, int $limit): array
    {
        /** @var list<Delivery> */
        return $this->manager->get()->createQueryBuilder()->select('d')->from(Delivery::class, 'd')
            ->where('d.status = :pending')->andWhere('d.frequency = :immediate')->andWhere('d.nextAttemptAt <= :now')
            ->setParameter('pending', DeliveryStatus::Pending)->setParameter('immediate', Frequency::Immediate)->setParameter('now', $now)
            ->orderBy('d.nextAttemptAt')->addOrderBy('d.id')->setMaxResults($limit)->getQuery()->getResult();
    }

    public function pendingDigest(Frequency $frequency, int $limit): array
    {
        /** @var list<Delivery> */
        return $this->manager->get()->createQueryBuilder()->select('d')->from(Delivery::class, 'd')
            ->where('d.status = :pending')->andWhere('d.frequency = :frequency')
            ->setParameter('pending', DeliveryStatus::Pending)->setParameter('frequency', $frequency)
            ->orderBy('d.nextAttemptAt')->addOrderBy('d.id')->setMaxResults($limit)->getQuery()->getResult();
    }

    public function deleteTeam(Uuid $teamId): void
    {
        $this->manager->get()->createQueryBuilder()->delete(Delivery::class, 'd')->where('d.teamId = :team')->setParameter('team', $teamId, 'uuid')->getQuery()->execute();
    }
}
