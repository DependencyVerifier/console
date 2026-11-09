<?php

declare(strict_types=1);

namespace Identity\Domain\Repository;

use Identity\Domain\Model\PasswordResetRequest;
use Identity\Domain\Model\UserId;
use Symfony\Component\Uid\Uuid;

interface PasswordResetRequestRepository
{
    public function find(Uuid $id): ?PasswordResetRequest;

    public function findByTokenHash(string $tokenHash): ?PasswordResetRequest;

    /**
     * @return list<PasswordResetRequest>
     */
    public function findOpenFor(UserId $userId): array;

    public function add(PasswordResetRequest $request): void;
}
