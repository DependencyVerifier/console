<?php

declare(strict_types=1);

namespace Tests\Identity\Support;

use Identity\Domain\Model\EmailAddress;
use Identity\Domain\Model\User;
use Identity\Domain\Model\UserId;
use Identity\Domain\Repository\UserRepository;

final class InMemoryUsers implements UserRepository
{
    /** @var array<string, User> */
    public array $users = [];

    /** @var list<string> */
    public array $locked = [];

    public function __construct(User ...$users)
    {
        foreach ($users as $user) {
            $this->users[$user->id()->toString()] = $user;
        }
    }

    public function find(UserId $id): ?User
    {
        return $this->users[$id->toString()] ?? null;
    }

    public function lockById(UserId $id): ?User
    {
        $this->locked[] = $id->toString();

        return $this->find($id);
    }

    public function findByEmail(EmailAddress $email): ?User
    {
        foreach ($this->users as $user) {
            if ($user->email()->equals($email)) {
                return $user;
            }
        }

        return null;
    }

    public function add(User $user): void
    {
        $this->users[$user->id()->toString()] = $user;
    }

    public function countActiveSuperAdmins(): int
    {
        return \count(array_filter($this->users, static fn (User $user): bool => $user->isSuperAdmin() && $user->status()->isActive()));
    }
}
