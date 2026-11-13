<?php

declare(strict_types=1);

namespace Notification\Domain\Repository;

use Notification\Domain\Model\NotificationRule;
use Symfony\Component\Uid\Uuid;

interface NotificationRuleRepository
{
    public function find(Uuid $id): ?NotificationRule;

    /**
     * The rules of a project, or of the fleet of the team (no project), the oldest first.
     *
     * @return list<NotificationRule>
     */
    public function ofScope(Uuid $teamId, ?Uuid $projectId): array;

    /**
     * Every rule calling this webhook.
     *
     * @return list<NotificationRule>
     */
    public function callingWebhook(Uuid $webhookId): array;

    /**
     * The fleet rules telling of silent agents, of every team.
     *
     * @return list<NotificationRule>
     */
    public function tellingSilentAgents(): array;

    public function hasProject(Uuid $projectId): bool;

    public function hasEnvironment(Uuid $environmentId): bool;

    public function add(NotificationRule $rule): void;

    public function remove(NotificationRule $rule): void;

    public function deleteTeam(Uuid $teamId): void;
}
