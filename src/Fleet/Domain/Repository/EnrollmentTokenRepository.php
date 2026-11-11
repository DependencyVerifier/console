<?php

declare(strict_types=1);

namespace Fleet\Domain\Repository;

use Fleet\Domain\Model\EnrollmentToken;
use Fleet\Domain\Model\EnrollmentTokenId;
use Symfony\Component\Uid\Uuid;

interface EnrollmentTokenRepository
{
    public function find(EnrollmentTokenId $id): ?EnrollmentToken;

    public function findByHash(string $tokenHash): ?EnrollmentToken;

    public function add(EnrollmentToken $token): void;

    /**
     * Reusable tokens not revoked, not expired and not used up (maxReusableEnrollmentTokens).
     */
    public function countActiveReusable(Uuid $teamId, \DateTimeImmutable $now): int;

    /**
     * Does a usable token default to this environment? (it then cannot be deleted).
     */
    public function hasActiveForEnvironment(Uuid $environmentId, \DateTimeImmutable $now): bool;
}
