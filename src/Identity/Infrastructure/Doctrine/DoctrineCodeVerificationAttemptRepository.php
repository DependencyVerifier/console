<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Identity\Domain\Model\CodeVerificationAttempt;
use Identity\Domain\Model\UserId;
use Identity\Domain\Repository\CodeVerificationAttemptRepository;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineCodeVerificationAttemptRepository implements CodeVerificationAttemptRepository
{
    public function __construct(private ManagerRegistry $registry)
    {
    }

    public function add(CodeVerificationAttempt $attempt): void
    {
        $this->manager()->persist($attempt);
    }

    public function find(Uuid $attemptId): ?CodeVerificationAttempt
    {
        return $this->manager()->find(CodeVerificationAttempt::class, $attemptId);
    }

    public function pruneOlderThan(UserId $userId, \DateTimeImmutable $before): void
    {
        $this->manager()->getConnection()->executeStatement(
            'DELETE FROM identity_code_verification_attempt WHERE user_id = :user AND created_at < :before',
            ['user' => $userId->toString(), 'before' => $before->format('Y-m-d H:i:s')],
        );
    }

    private function manager(): EntityManagerInterface
    {
        $manager = $this->registry->getManagerForClass(CodeVerificationAttempt::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('No entity manager for CodeVerificationAttempt.');
        }

        return $manager;
    }
}
