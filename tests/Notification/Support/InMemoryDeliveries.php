<?php

declare(strict_types=1);

namespace Tests\Notification\Support;

use Notification\Domain\Model\Delivery;
use Notification\Domain\Model\DeliveryStatus;
use Notification\Domain\Model\Frequency;
use Notification\Domain\Repository\DeliveryRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryDeliveries implements DeliveryRepository
{
    /** @var list<Delivery> */
    public array $deliveries = [];

    public function find(Uuid $id): ?Delivery
    {
        return array_find($this->deliveries, static fn (Delivery $d): bool => $d->id()->equals($id));
    }

    public function add(Delivery $delivery): void
    {
        $this->deliveries[] = $delivery;
    }

    public function due(\DateTimeImmutable $now, int $limit): array
    {
        return \array_slice(array_values(array_filter($this->deliveries, static fn (Delivery $d): bool => Frequency::Immediate === $d->frequency() && $d->isDue($now))), 0, $limit);
    }

    public function pendingDigest(Frequency $frequency, int $limit): array
    {
        return \array_slice(array_values(array_filter($this->deliveries, static fn (Delivery $d): bool => $frequency === $d->frequency() && DeliveryStatus::Pending === $d->status())), 0, $limit);
    }

    public function deleteTeam(Uuid $teamId): void
    {
        $this->deliveries = array_values(array_filter($this->deliveries, static fn (Delivery $d): bool => !$d->teamId()->equals($teamId)));
    }
}
