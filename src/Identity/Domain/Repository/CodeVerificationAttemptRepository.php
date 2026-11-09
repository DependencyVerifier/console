<?php

declare(strict_types=1);

namespace Identity\Domain\Repository;

use Identity\Domain\Model\CodeVerificationAttempt;
use Identity\Domain\Model\UserId;
use Symfony\Component\Uid\Uuid;

interface CodeVerificationAttemptRepository
{
    public function add(CodeVerificationAttempt $attempt): void;

    public function find(Uuid $attemptId): ?CodeVerificationAttempt;

    public function pruneOlderThan(UserId $userId, \DateTimeImmutable $before): void;
}
