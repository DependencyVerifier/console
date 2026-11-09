<?php

declare(strict_types=1);

namespace Tests\Identity\Support;

use Identity\Domain\Model\CodeVerificationAttempt;
use Identity\Domain\Model\UserId;
use Identity\Domain\Repository\CodeVerificationAttemptRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryAttempts implements CodeVerificationAttemptRepository
{
    /** @var array<string, CodeVerificationAttempt> */
    public array $attempts = [];

    /** @var list<array{string, \DateTimeImmutable}> */
    public array $pruned = [];

    public function add(CodeVerificationAttempt $attempt): void
    {
        $this->attempts[$attempt->id()->toRfc4122()] = $attempt;
    }

    public function find(Uuid $attemptId): ?CodeVerificationAttempt
    {
        return $this->attempts[$attemptId->toRfc4122()] ?? null;
    }

    public function pruneOlderThan(UserId $userId, \DateTimeImmutable $before): void
    {
        $this->pruned[] = [$userId->toString(), $before];
    }
}
