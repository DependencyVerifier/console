<?php

declare(strict_types=1);

namespace Tests\Identity\Support;

use Identity\Domain\Model\PasswordResetRequest;
use Identity\Domain\Model\UserId;
use Identity\Domain\Repository\PasswordResetRequestRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryResetRequests implements PasswordResetRequestRepository
{
    /** @var array<string, PasswordResetRequest> */
    public array $requests = [];

    /** @var array<string, string> */
    private array $hashes = [];

    public function find(Uuid $id): ?PasswordResetRequest
    {
        return $this->requests[$id->toRfc4122()] ?? null;
    }

    public function findByTokenHash(string $tokenHash): ?PasswordResetRequest
    {
        $id = array_search($tokenHash, $this->hashes, true);

        return false === $id ? null : $this->requests[$id];
    }

    public function findOpenFor(UserId $userId): array
    {
        return array_values(array_filter($this->requests, static fn (PasswordResetRequest $r): bool => $r->userId()->equals($userId)));
    }

    public function add(PasswordResetRequest $request): void
    {
        $this->requests[$request->id()->toRfc4122()] = $request;
    }
}
