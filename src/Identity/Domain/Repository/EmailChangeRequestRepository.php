<?php

declare(strict_types=1);

namespace Identity\Domain\Repository;

use Identity\Domain\Model\EmailChangeRequest;
use Identity\Domain\Model\UserId;
use Symfony\Component\Uid\Uuid;

interface EmailChangeRequestRepository
{
    public function find(Uuid $id): ?EmailChangeRequest;

    public function findByTokenHash(string $tokenHash): ?EmailChangeRequest;

    /**
     * @return list<EmailChangeRequest> not consumed nor invalidated
     */
    public function findOpenFor(UserId $userId): array;

    public function add(EmailChangeRequest $request): void;
}
