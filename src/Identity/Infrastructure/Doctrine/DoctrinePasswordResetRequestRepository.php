<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Identity\Domain\Model\PasswordResetRequest;
use Identity\Domain\Model\UserId;
use Identity\Domain\Repository\PasswordResetRequestRepository;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrinePasswordResetRequestRepository implements PasswordResetRequestRepository
{
    public function __construct(private ManagerRegistry $registry)
    {
    }

    public function find(Uuid $id): ?PasswordResetRequest
    {
        return $this->manager()->find(PasswordResetRequest::class, $id);
    }

    public function findByTokenHash(string $tokenHash): ?PasswordResetRequest
    {
        return $this->manager()->getRepository(PasswordResetRequest::class)->findOneBy(['tokenHash' => $tokenHash]);
    }

    public function findOpenFor(UserId $userId): array
    {
        /** @var list<PasswordResetRequest> */
        return $this->manager()->createQueryBuilder()
            ->select('r')
            ->from(PasswordResetRequest::class, 'r')
            ->where('r.userId = :user')
            ->andWhere('r.consumedAt IS NULL')
            ->andWhere('r.invalidatedAt IS NULL')
            ->setParameter('user', $userId->value, 'uuid')
            ->getQuery()
            ->getResult();
    }

    public function add(PasswordResetRequest $request): void
    {
        $this->manager()->persist($request);
    }

    private function manager(): EntityManagerInterface
    {
        $manager = $this->registry->getManagerForClass(PasswordResetRequest::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('No entity manager for PasswordResetRequest.');
        }

        return $manager;
    }
}
