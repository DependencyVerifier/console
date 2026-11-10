<?php

declare(strict_types=1);

namespace Organization\Infrastructure\Doctrine;

use Organization\Domain\Model\Project;
use Organization\Domain\Model\ProjectId;
use Organization\Domain\Model\ProjectMembership;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Repository\ProjectRepository;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineProjectRepository implements ProjectRepository
{
    public function __construct(private OrganizationManager $manager)
    {
    }

    public function find(ProjectId $id): ?Project
    {
        return $this->manager->get()->find(Project::class, $id->value);
    }

    public function add(Project $project): void
    {
        $this->manager->get()->persist($project);
    }

    public function remove(Project $project): void
    {
        $this->manager->get()->remove($project);
    }

    public function countOfTeam(TeamId $teamId): int
    {
        return (int) $this->manager->get()->createQueryBuilder()
            ->select('COUNT(p.id)')->from(Project::class, 'p')
            ->where('p.teamId = :team')->setParameter('team', $teamId->value, 'uuid')
            ->getQuery()->getSingleScalarResult();
    }

    public function slugExists(TeamId $teamId, string $slug): bool
    {
        return null !== $this->manager->get()->getRepository(Project::class)->findOneBy(['teamId' => $teamId->value, 'slug' => $slug]);
    }

    public function ofTeamWithMember(TeamId $teamId, Uuid $userId): array
    {
        /** @var list<Project> */
        return $this->manager->get()->createQueryBuilder()
            ->select('p')->from(Project::class, 'p')
            ->innerJoin(ProjectMembership::class, 'm', 'WITH', 'm.project = p')
            ->where('p.teamId = :team')->andWhere('m.userId = :user')
            ->setParameter('team', $teamId->value, 'uuid')->setParameter('user', $userId, 'uuid')
            ->getQuery()->getResult();
    }

    public function ofTeam(TeamId $teamId): array
    {
        /** @var list<Project> */
        return $this->manager->get()->createQueryBuilder()
            ->select('p')->from(Project::class, 'p')
            ->where('p.teamId = :team')->setParameter('team', $teamId->value, 'uuid')
            ->getQuery()->getResult();
    }
}
