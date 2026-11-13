<?php

declare(strict_types=1);

namespace Notification\Domain\Repository;

use Notification\Domain\Model\Delivery;
use Notification\Domain\Model\Frequency;
use Symfony\Component\Uid\Uuid;

interface DeliveryRepository
{
    public function find(Uuid $id): ?Delivery;

    public function add(Delivery $delivery): void;

    /**
     * The immediate deliveries pending and due, the oldest first.
     *
     * @return list<Delivery>
     */
    public function due(\DateTimeImmutable $now, int $limit): array;

    /**
     * The pending deliveries of a digest, the oldest first.
     *
     * @return list<Delivery>
     */
    public function pendingDigest(Frequency $frequency, int $limit): array;

    public function deleteTeam(Uuid $teamId): void;
}
