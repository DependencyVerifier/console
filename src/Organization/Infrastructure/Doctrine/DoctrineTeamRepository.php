<?php

declare(strict_types=1);

namespace Organization\Infrastructure\Doctrine;

use Doctrine\DBAL\LockMode;
use Organization\Domain\Model\Team;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Model\TeamMembership;
use Organization\Domain\Repository\TeamRepository;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineTeamRepository implements TeamRepository
{
    public function __construct(private OrganizationManager $manager)
    {
    }

    public function find(TeamId $id): ?Team
    {
        return $this->manager->get()->find(Team::class, $id->value);
    }

    public function lockById(TeamId $id): ?Team
    {
        return $this->manager->get()->find(Team::class, $id->value, LockMode::PESSIMISTIC_WRITE);
    }

    public function add(Team $team): void
    {
        $this->manager->get()->persist($team);
    }

    public function slugExists(string $slug): bool
    {
        return null !== $this->manager->get()->getRepository(Team::class)->findOneBy(['slug' => $slug]);
    }

    public function idsOfMember(Uuid $userId): array
    {
        /** @var list<array{id: Uuid}> $rows */
        $rows = $this->manager->get()->createQueryBuilder()
            ->select('IDENTITY(m.team) AS id')
            ->from(TeamMembership::class, 'm')
            ->where('m.userId = :user')
            ->setParameter('user', $userId, 'uuid')
            ->orderBy('m.team', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): TeamId => TeamId::fromString((string) $row['id']), $rows);
    }

    public function remove(Team $team): void
    {
        $this->manager->get()->remove($team);
    }
}
