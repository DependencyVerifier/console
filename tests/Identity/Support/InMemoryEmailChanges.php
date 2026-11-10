<?php

declare(strict_types=1);

namespace Tests\Identity\Support;

use Identity\Domain\Model\EmailChangeRequest;
use Identity\Domain\Model\UserId;
use Identity\Domain\Repository\EmailChangeRequestRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryEmailChanges implements EmailChangeRequestRepository
{
    /** @var array<string, EmailChangeRequest> */
    public array $requests = [];

    public function find(Uuid $id): ?EmailChangeRequest
    {
        return $this->requests[$id->toRfc4122()] ?? null;
    }

    public function findByTokenHash(string $tokenHash): ?EmailChangeRequest
    {
        foreach ($this->requests as $request) {
            if ($tokenHash === new \ReflectionProperty(EmailChangeRequest::class, 'tokenHash')->getValue($request)) {
                return $request;
            }
        }

        return null;
    }

    public function findOpenFor(UserId $userId): array
    {
        return array_values(array_filter($this->requests, static fn (EmailChangeRequest $r): bool => $r->userId()->equals($userId)));
    }

    public function add(EmailChangeRequest $request): void
    {
        $this->requests[$request->id()->toRfc4122()] = $request;
    }
}
