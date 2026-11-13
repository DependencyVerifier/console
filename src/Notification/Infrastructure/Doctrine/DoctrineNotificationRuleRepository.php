<?php

declare(strict_types=1);

namespace Notification\Infrastructure\Doctrine;

use Notification\Domain\Model\NotificationEvent;
use Notification\Domain\Model\NotificationRule;
use Notification\Domain\Repository\NotificationRuleRepository;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineNotificationRuleRepository implements NotificationRuleRepository
{
    public function __construct(private NotificationManager $manager)
    {
    }

    public function find(Uuid $id): ?NotificationRule
    {
        return $this->manager->get()->find(NotificationRule::class, $id);
    }

    public function ofScope(Uuid $teamId, ?Uuid $projectId): array
    {
        return $this->manager->get()->getRepository(NotificationRule::class)->findBy(['teamId' => $teamId, 'projectId' => $projectId], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    public function callingWebhook(Uuid $webhookId): array
    {
        return $this->ids('SELECT id FROM notification_rule WHERE webhook_ids::jsonb @> jsonb_build_array(CAST(:id AS text))', ['id' => $webhookId->toRfc4122()]);
    }

    public function tellingSilentAgents(): array
    {
        return $this->ids('SELECT id FROM notification_rule WHERE project_id IS NULL AND events::jsonb @> jsonb_build_array(CAST(:event AS text))', ['event' => NotificationEvent::AgentSilent->value]);
    }

    public function hasProject(Uuid $projectId): bool
    {
        return [] !== $this->manager->get()->getRepository(NotificationRule::class)->findBy(['projectId' => $projectId], limit: 1);
    }

    public function hasEnvironment(Uuid $environmentId): bool
    {
        return [] !== $this->manager->get()->getRepository(NotificationRule::class)->findBy(['environmentId' => $environmentId], limit: 1);
    }

    public function add(NotificationRule $rule): void
    {
        $this->manager->get()->persist($rule);
    }

    public function remove(NotificationRule $rule): void
    {
        $this->manager->get()->remove($rule);
    }

    public function deleteTeam(Uuid $teamId): void
    {
        $this->manager->get()->createQueryBuilder()->delete(NotificationRule::class, 'r')->where('r.teamId = :team')->setParameter('team', $teamId, 'uuid')->getQuery()->execute();
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return list<NotificationRule>
     */
    private function ids(string $sql, array $parameters): array
    {
        $manager = $this->manager->get();
        /** @var list<string> $ids */
        $ids = $manager->getConnection()->fetchFirstColumn($sql, $parameters);

        return [] === $ids ? [] : $manager->getRepository(NotificationRule::class)->findBy(['id' => array_map(Uuid::fromString(...), $ids)], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }
}
