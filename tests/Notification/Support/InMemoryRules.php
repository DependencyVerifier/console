<?php

declare(strict_types=1);

namespace Tests\Notification\Support;

use Notification\Domain\Model\NotificationEvent;
use Notification\Domain\Model\NotificationRule;
use Notification\Domain\Repository\NotificationRuleRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryRules implements NotificationRuleRepository
{
    /** @var list<NotificationRule> in the order added */
    public array $rules = [];

    public function find(Uuid $id): ?NotificationRule
    {
        return array_find($this->rules, static fn (NotificationRule $r): bool => $r->id()->equals($id));
    }

    public function ofScope(Uuid $teamId, ?Uuid $projectId): array
    {
        return array_values(array_filter($this->rules, static fn (NotificationRule $r): bool => $r->teamId()->equals($teamId) && $r->projectId()?->toRfc4122() === $projectId?->toRfc4122()));
    }

    public function callingWebhook(Uuid $webhookId): array
    {
        return array_values(array_filter($this->rules, static fn (NotificationRule $r): bool => \in_array($webhookId->toRfc4122(), $r->webhookIds(), true)));
    }

    public function tellingSilentAgents(): array
    {
        return array_values(array_filter($this->rules, static fn (NotificationRule $r): bool => null === $r->projectId() && \in_array(NotificationEvent::AgentSilent, $r->events(), true)));
    }

    public function hasProject(Uuid $projectId): bool
    {
        return array_any($this->rules, static fn (NotificationRule $r): bool => true === $r->projectId()?->equals($projectId));
    }

    public function hasEnvironment(Uuid $environmentId): bool
    {
        return array_any($this->rules, static fn (NotificationRule $r): bool => true === $r->environmentId()?->equals($environmentId));
    }

    public function add(NotificationRule $rule): void
    {
        $this->rules[] = $rule;
    }

    public function remove(NotificationRule $rule): void
    {
        $this->rules = array_values(array_filter($this->rules, static fn (NotificationRule $r): bool => $r !== $rule));
    }

    public function deleteTeam(Uuid $teamId): void
    {
        $this->rules = array_values(array_filter($this->rules, static fn (NotificationRule $r): bool => !$r->teamId()->equals($teamId)));
    }
}
