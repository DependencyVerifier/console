<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Doctrine;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Identity\Domain\Model\EmailAddress;
use Identity\Domain\Model\User;
use Identity\Domain\Model\UserId;
use Identity\Domain\Repository\UserRepository;

/**
 * Through ManagerRegistry, never an EntityManager injected once: the bus may reset a closed manager.
 */
final readonly class DoctrineUserRepository implements UserRepository
{
    public function __construct(private ManagerRegistry $registry)
    {
    }

    public function find(UserId $id): ?User
    {
        return $this->manager()->find(User::class, $id->value);
    }

    public function lockById(UserId $id): ?User
    {
        return $this->manager()->find(User::class, $id->value, LockMode::PESSIMISTIC_WRITE);
    }

    public function findByEmail(EmailAddress $email): ?User
    {
        return $this->manager()->getRepository(User::class)->findOneBy(['email.value' => $email->value()]);
    }

    public function add(User $user): void
    {
        $this->manager()->persist($user);
    }

    public function countActiveSuperAdmins(): int
    {
        return (int) $this->manager()->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(User::class, 'u')
            ->where('u.privileges.superAdmin = true')
            ->andWhere("u.status.kind = 'active'")
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function manager(): EntityManagerInterface
    {
        $manager = $this->registry->getManagerForClass(User::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('No entity manager for User.');
        }

        return $manager;
    }
}
