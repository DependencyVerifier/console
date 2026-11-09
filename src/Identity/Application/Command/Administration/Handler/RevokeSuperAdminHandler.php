<?php

declare(strict_types=1);

namespace Identity\Application\Command\Administration\Handler;

use Identity\Application\Command\Administration\RevokeSuperAdmin;
use Identity\Application\Security\AccountAccess;
use Identity\Application\Security\LastSuperAdminGuard;
use Identity\Domain\Model\SuperAdminRevocationForbidden;
use Identity\Domain\Model\UserId;
use Shared\Application\Bus\CommandHandler;
use Shared\Domain\Authorization\Permission;

final readonly class RevokeSuperAdminHandler implements CommandHandler
{
    public function __construct(private AccountAccess $access, private LastSuperAdminGuard $guard)
    {
    }

    public function __invoke(RevokeSuperAdmin $command): void
    {
        $this->access->assertGranted(Permission::InstanceSuperAdminManage);
        $userId = UserId::fromString($command->userId);
        if ($this->access->isActor($userId)) {
            throw new SuperAdminRevocationForbidden();
        }

        $this->guard->lock();
        $user = $this->access->lock($userId);
        $this->guard->assertNotLast($user);
        $user->revokeSuperAdmin();
    }
}
