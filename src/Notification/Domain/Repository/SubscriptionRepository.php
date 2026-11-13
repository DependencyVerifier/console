<?php

declare(strict_types=1);

namespace Notification\Domain\Repository;

use Notification\Domain\Model\Subscription;
use Symfony\Component\Uid\Uuid;

interface SubscriptionRepository
{
    public function find(Uuid $id): ?Subscription;

    /**
     * The subscription of an account to a project, or to the fleet of the team (no project).
     */
    public function of(Uuid $userId, Uuid $teamId, ?Uuid $projectId): ?Subscription;

    /**
     * Every subscription to a project, or to the fleet of the team, `off` included.
     *
     * @return list<Subscription>
     */
    public function ofScope(Uuid $teamId, ?Uuid $projectId): array;

    /**
     * The recipients attached by a manager to the projects of the team (each account once): with the addresses still
     * invited, what `maxNotificationOnlyAccounts` counts.
     *
     * @return list<string> account ids
     */
    public function attachedRecipients(Uuid $teamId): array;

    public function add(Subscription $subscription): void;

    public function remove(Subscription $subscription): void;

    public function deleteTeam(Uuid $teamId): void;
}
