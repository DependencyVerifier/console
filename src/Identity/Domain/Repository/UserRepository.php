<?php

declare(strict_types=1);

namespace Identity\Domain\Repository;

use Identity\Domain\Model\EmailAddress;
use Identity\Domain\Model\User;
use Identity\Domain\Model\UserId;

interface UserRepository
{
    public function find(UserId $id): ?User;

    /**
     * SELECT … FOR UPDATE inside the command transaction: every command changing credentials or second factor
     * goes through it, so a code cannot be accepted twice.
     */
    public function lockById(UserId $id): ?User;

    public function findByEmail(EmailAddress $email): ?User;

    public function add(User $user): void;

    public function countActiveSuperAdmins(): int;
}
