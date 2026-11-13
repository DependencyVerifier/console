<?php

declare(strict_types=1);

namespace Notification\Infrastructure\Doctrine;

use Notification\Domain\Model\RecipientInvitation;
use Notification\Domain\Model\RecipientInvitationStatus;
use Notification\Domain\Repository\RecipientInvitationRepository;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineRecipientInvitationRepository implements RecipientInvitationRepository
{
    public function __construct(private NotificationManager $manager)
    {
    }

    public function find(Uuid $id): ?RecipientInvitation
    {
        return $this->manager->get()->find(RecipientInvitation::class, $id);
    }

    public function findByTokenHash(string $hash): ?RecipientInvitation
    {
        return $this->manager->get()->getRepository(RecipientInvitation::class)->findOneBy(['tokenHash' => $hash]);
    }

    public function pending(Uuid $projectId, string $email): ?RecipientInvitation
    {
        return $this->manager->get()->getRepository(RecipientInvitation::class)->findOneBy(['projectId' => $projectId, 'email' => $email, 'status' => RecipientInvitationStatus::Pending]);
    }

    public function pendingOfProject(Uuid $projectId, \DateTimeImmutable $now): array
    {
        /** @var list<RecipientInvitation> */
        return $this->manager->get()->createQueryBuilder()->select('i')->from(RecipientInvitation::class, 'i')
            ->where('i.projectId = :project')->andWhere('i.status = :pending')->andWhere('i.expiresAt > :now')
            ->setParameter('project', $projectId, 'uuid')->setParameter('pending', RecipientInvitationStatus::Pending)->setParameter('now', $now)
            ->orderBy('i.createdAt')->addOrderBy('i.id')->getQuery()->getResult();
    }

    public function pendingEmailsOfTeam(Uuid $teamId, \DateTimeImmutable $now): array
    {
        /** @var list<string> */
        return $this->manager->get()->getConnection()->fetchFirstColumn(
            "SELECT DISTINCT email FROM notification_recipient_invitation WHERE team_id = :team AND status = 'pending' AND expires_at > :now ORDER BY email",
            ['team' => $teamId->toRfc4122(), 'now' => $now->format('Y-m-d H:i:s')],
        );
    }

    public function add(RecipientInvitation $invitation): void
    {
        $this->manager->get()->persist($invitation);
    }

    public function deleteTeam(Uuid $teamId): void
    {
        $this->manager->get()->createQueryBuilder()->delete(RecipientInvitation::class, 'i')->where('i.teamId = :team')->setParameter('team', $teamId, 'uuid')->getQuery()->execute();
    }
}
