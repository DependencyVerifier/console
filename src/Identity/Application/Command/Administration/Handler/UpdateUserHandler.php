<?php

declare(strict_types=1);

namespace Identity\Application\Command\Administration\Handler;

use Identity\Application\Command\Administration\UpdateUser;
use Identity\Application\Security\AccountAccess;
use Identity\Domain\Model\UserId;
use Shared\Application\Bus\CommandHandler;
use Shared\Domain\Authorization\Permission;

final readonly class UpdateUserHandler implements CommandHandler
{
    public function __construct(private AccountAccess $access)
    {
    }

    public function __invoke(UpdateUser $command): void
    {
        $this->access->assertGranted(Permission::InstanceUsersManage);
        $this->access->lock(UserId::fromString($command->userId))->rename($command->displayName);
    }
}
