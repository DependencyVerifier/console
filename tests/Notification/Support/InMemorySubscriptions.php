<?php

declare(strict_types=1);

namespace Tests\Notification\Support;

use Notification\Domain\Model\Subscription;
use Notification\Domain\Repository\SubscriptionRepository;
use Symfony\Component\Uid\Uuid;

final class InMemorySubscriptions implements SubscriptionRepository
{
    /** @var list<Subscription> */
    public array $subscriptions = [];

    public function find(Uuid $id): ?Subscription
    {
        return array_find($this->subscriptions, static fn (Subscription $s): bool => $s->id()->equals($id));
    }

    public function of(Uuid $userId, Uuid $teamId, ?Uuid $projectId): ?Subscription
    {
        return array_find($this->ofScope($teamId, $projectId), static fn (Subscription $s): bool => $s->userId()->equals($userId));
    }

    public function ofScope(Uuid $teamId, ?Uuid $projectId): array
    {
        return array_values(array_filter($this->subscriptions, static fn (Subscription $s): bool => $s->teamId()->equals($teamId) && $s->projectId()?->toRfc4122() === $projectId?->toRfc4122()));
    }

    public function add(Subscription $subscription): void
    {
        $this->subscriptions[] = $subscription;
    }

    public function remove(Subscription $subscription): void
    {
        $this->subscriptions = array_values(array_filter($this->subscriptions, static fn (Subscription $s): bool => $s !== $subscription));
    }

    public function deleteTeam(Uuid $teamId): void
    {
        $this->subscriptions = array_values(array_filter($this->subscriptions, static fn (Subscription $s): bool => !$s->teamId()->equals($teamId)));
    }
}
