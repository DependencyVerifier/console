<?php

declare(strict_types=1);

namespace Notification\Infrastructure\Doctrine;

use Notification\Domain\Model\WebhookChannel;
use Notification\Domain\Repository\WebhookChannelRepository;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineWebhookChannelRepository implements WebhookChannelRepository
{
    public function __construct(private NotificationManager $manager)
    {
    }

    public function find(Uuid $id): ?WebhookChannel
    {
        return $this->manager->get()->find(WebhookChannel::class, $id);
    }

    public function ofTeam(Uuid $teamId): array
    {
        return $this->manager->get()->getRepository(WebhookChannel::class)->findBy(['teamId' => $teamId], ['name' => 'ASC', 'id' => 'ASC']);
    }

    public function add(WebhookChannel $channel): void
    {
        $this->manager->get()->persist($channel);
    }

    public function remove(WebhookChannel $channel): void
    {
        $this->manager->get()->remove($channel);
    }

    public function deleteTeam(Uuid $teamId): void
    {
        $this->manager->get()->createQueryBuilder()->delete(WebhookChannel::class, 'w')->where('w.teamId = :team')->setParameter('team', $teamId, 'uuid')->getQuery()->execute();
    }
}
