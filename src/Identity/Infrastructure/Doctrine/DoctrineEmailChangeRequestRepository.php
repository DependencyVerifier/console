<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Identity\Domain\Model\EmailChangeRequest;
use Identity\Domain\Model\UserId;
use Identity\Domain\Repository\EmailChangeRequestRepository;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineEmailChangeRequestRepository implements EmailChangeRequestRepository
{
    public function __construct(private ManagerRegistry $registry)
    {
    }

    public function find(Uuid $id): ?EmailChangeRequest
    {
        return $this->manager()->find(EmailChangeRequest::class, $id);
    }

    public function findByTokenHash(string $tokenHash): ?EmailChangeRequest
    {
        return $this->manager()->getRepository(EmailChangeRequest::class)->findOneBy(['tokenHash' => $tokenHash]);
    }

    public function findOpenFor(UserId $userId): array
    {
        /** @var list<EmailChangeRequest> */
        return $this->manager()->createQueryBuilder()
            ->select('r')
            ->from(EmailChangeRequest::class, 'r')
            ->where('r.userId = :user')
            ->andWhere('r.consumedAt IS NULL')
            ->andWhere('r.invalidatedAt IS NULL')
            ->setParameter('user', $userId->value, 'uuid')
            ->getQuery()
            ->getResult();
    }

    public function add(EmailChangeRequest $request): void
    {
        $this->manager()->persist($request);
    }

    private function manager(): EntityManagerInterface
    {
        $manager = $this->registry->getManagerForClass(EmailChangeRequest::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('No entity manager for EmailChangeRequest.');
        }

        return $manager;
    }
}
