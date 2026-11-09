<?php

declare(strict_types=1);

namespace Identity\Application\Command\Administration\Handler;

use Identity\Application\Command\Administration\RestoreUser;
use Identity\Application\Security\AccountAccess;
use Identity\Domain\Model\UserId;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Shared\Domain\Authorization\Permission;

final readonly class RestoreUserHandler implements CommandHandler
{
    public function __construct(private AccountAccess $access, private ClockInterface $clock)
    {
    }

    public function __invoke(RestoreUser $command): void
    {
        $this->access->assertGranted(Permission::InstanceUsersManage);
        $this->access->lock(UserId::fromString($command->userId))->restore($this->clock->now());
    }
}
