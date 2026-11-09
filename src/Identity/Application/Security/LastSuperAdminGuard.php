<?php

declare(strict_types=1);

namespace Identity\Application\Security;

use Identity\Domain\Model\SuperAdminRevocationForbidden;
use Identity\Domain\Model\User;
use Identity\Domain\Repository\UserRepository;
use Shared\Application\Locking\TransactionLock;

/**
 * The instance always keeps an active super admin. The count runs under a constant advisory lock, so two
 * concurrent revocations cannot both see "two left".
 */
final readonly class LastSuperAdminGuard
{
    public const string LOCK = 'identity.active_super_admins';

    public function __construct(private TransactionLock $lock, private UserRepository $users)
    {
    }

    public function lock(): void
    {
        $this->lock->acquire(self::LOCK);
    }

    /**
     * Call after lock(): refuses when $user is the last active super admin.
     */
    public function assertNotLast(User $user): void
    {
        if ($user->isSuperAdmin() && $user->status()->isActive() && $this->users->countActiveSuperAdmins() <= 1) {
            throw new SuperAdminRevocationForbidden();
        }
    }
}
