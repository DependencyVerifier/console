<?php

declare(strict_types=1);

namespace Identity\Application\Command\Administration\Handler;

use Identity\Application\Command\Administration\GrantSuperAdmin;
use Identity\Application\Security\AccountAccess;
use Identity\Domain\Model\UserId;
use Shared\Application\Bus\CommandHandler;
use Shared\Domain\Authorization\Permission;

final readonly class GrantSuperAdminHandler implements CommandHandler
{
    public function __construct(private AccountAccess $access)
    {
    }

    public function __invoke(GrantSuperAdmin $command): void
    {
        $this->access->assertGranted(Permission::InstanceSuperAdminManage);
        $this->access->lock(UserId::fromString($command->userId))->grantSuperAdmin();
    }
}
