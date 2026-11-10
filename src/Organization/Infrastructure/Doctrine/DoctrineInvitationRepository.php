<?php

declare(strict_types=1);

namespace Organization\Infrastructure\Doctrine;

use Organization\Domain\Model\Invitation;
use Organization\Domain\Model\InvitationId;
use Organization\Domain\Model\InvitationTarget;
use Organization\Domain\Model\ProjectId;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Repository\InvitationRepository;

final readonly class DoctrineInvitationRepository implements InvitationRepository
{
    public function __construct(private OrganizationManager $manager)
    {
    }

    public function find(InvitationId $id): ?Invitation
    {
        return $this->manager->get()->find(Invitation::class, $id->value);
    }

    public function findByTokenHash(string $tokenHash): ?Invitation
    {
        return $this->manager->get()->getRepository(Invitation::class)->findOneBy(['tokenHash' => $tokenHash]);
    }

    public function add(Invitation $invitation): void
    {
        $this->manager->get()->persist($invitation);
    }

    public function findOpen(string $email, InvitationTarget $target): ?Invitation
    {
        $query = $this->manager->get()->createQueryBuilder()
            ->select('i')->from(Invitation::class, 'i')
            ->where('i.email = :email')->andWhere("i.status = 'pending'")
            ->andWhere('i.target.teamId = :team')
            ->setParameter('email', $email)->setParameter('team', $target->teamId()->value, 'uuid')
            ->setMaxResults(1);
        $target->isProject()
            ? $query->andWhere('i.target.projectId = :project')->setParameter('project', $target->projectId()->value, 'uuid')
            : $query->andWhere('i.target.projectId IS NULL');

        /** @var ?Invitation */
        return $query->getQuery()->getOneOrNullResult();
    }

    public function countPendingOfTeam(TeamId $teamId, \DateTimeImmutable $now): int
    {
        return (int) $this->manager->get()->createQueryBuilder()
            ->select('COUNT(i.id)')->from(Invitation::class, 'i')
            ->where('i.target.teamId = :team')->andWhere("i.status = 'pending'")->andWhere('i.issuedAt > :since')
            ->setParameter('team', $teamId->value, 'uuid')->setParameter('since', $now->modify('-7 days'))
            ->getQuery()->getSingleScalarResult();
    }

    public function openOfProject(ProjectId $projectId): array
    {
        /** @var list<Invitation> */
        return $this->manager->get()->createQueryBuilder()
            ->select('i')->from(Invitation::class, 'i')
            ->where('i.target.projectId = :project')->andWhere("i.status = 'pending'")
            ->setParameter('project', $projectId->value, 'uuid')
            ->getQuery()->getResult();
    }

    public function openOfTeam(TeamId $teamId): array
    {
        /** @var list<Invitation> */
        return $this->manager->get()->createQueryBuilder()
            ->select('i')->from(Invitation::class, 'i')
            ->where('i.target.teamId = :team')->andWhere("i.status = 'pending'")
            ->setParameter('team', $teamId->value, 'uuid')
            ->getQuery()->getResult();
    }
}
