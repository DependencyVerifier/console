<?php

declare(strict_types=1);

namespace Notification\Infrastructure\Doctrine;

use Notification\Domain\Model\Subscription;
use Notification\Domain\Repository\SubscriptionRepository;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineSubscriptionRepository implements SubscriptionRepository
{
    public function __construct(private NotificationManager $manager)
    {
    }

    public function find(Uuid $id): ?Subscription
    {
        return $this->manager->get()->find(Subscription::class, $id);
    }

    public function of(Uuid $userId, Uuid $teamId, ?Uuid $projectId): ?Subscription
    {
        return $this->manager->get()->getRepository(Subscription::class)->findOneBy(['userId' => $userId, 'teamId' => $teamId, 'scopeKey' => Subscription::scopeKey($projectId)]);
    }

    public function ofScope(Uuid $teamId, ?Uuid $projectId): array
    {
        return $this->manager->get()->getRepository(Subscription::class)->findBy(['teamId' => $teamId, 'scopeKey' => Subscription::scopeKey($projectId)], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    public function findByConfirmationHash(string $hash): ?Subscription
    {
        return $this->manager->get()->getRepository(Subscription::class)->findOneBy(['confirmationHash' => $hash]);
    }

    public function attachedRecipients(Uuid $teamId): array
    {
        /** @var list<string> */
        return $this->manager->get()->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT CAST(user_id AS TEXT) FROM notification_subscription WHERE team_id = :team AND added_by IS NOT NULL ORDER BY 1',
            ['team' => $teamId->toRfc4122()],
        );
    }

    public function add(Subscription $subscription): void
    {
        $this->manager->get()->persist($subscription);
    }

    public function remove(Subscription $subscription): void
    {
        $this->manager->get()->remove($subscription);
    }

    public function deleteTeam(Uuid $teamId): void
    {
        $this->manager->get()->createQueryBuilder()->delete(Subscription::class, 's')->where('s.teamId = :team')->setParameter('team', $teamId, 'uuid')->getQuery()->execute();
    }
}
