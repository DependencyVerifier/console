<?php

declare(strict_types=1);

namespace Notification\Infrastructure\Doctrine;

use Notification\Domain\Model\Notice;
use Notification\Domain\Repository\NoticeRepository;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineNoticeRepository implements NoticeRepository
{
    public function __construct(private NotificationManager $manager)
    {
    }

    public function find(Uuid $id): ?Notice
    {
        return $this->manager->get()->find(Notice::class, $id);
    }

    public function has(Uuid $ruleId, string $dedupeKey): bool
    {
        return null !== $this->manager->get()->getRepository(Notice::class)->findOneBy(['ruleId' => $ruleId, 'dedupeKey' => $dedupeKey]);
    }

    public function findMany(array $ids): array
    {
        $notices = [];
        foreach ([] === $ids ? [] : $this->manager->get()->getRepository(Notice::class)->findBy(['id' => $ids]) as $notice) {
            $notices[$notice->id()->toRfc4122()] = $notice;
        }

        return $notices;
    }

    public function add(Notice $notice): void
    {
        $this->manager->get()->persist($notice);
    }

    public function deleteTeam(Uuid $teamId): void
    {
        $this->manager->get()->createQueryBuilder()->delete(Notice::class, 'n')->where('n.teamId = :team')->setParameter('team', $teamId, 'uuid')->getQuery()->execute();
    }
}
