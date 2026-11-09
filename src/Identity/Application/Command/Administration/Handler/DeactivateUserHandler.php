<?php

declare(strict_types=1);

namespace Identity\Application\Command\Administration\Handler;

use Identity\Application\Command\Administration\DeactivateUser;
use Identity\Application\Security\AccountAccess;
use Identity\Application\Security\LastSuperAdminGuard;
use Identity\Domain\Model\SelfManagementForbidden;
use Identity\Domain\Model\UserId;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Shared\Domain\Authorization\Permission;

final readonly class DeactivateUserHandler implements CommandHandler
{
    public function __construct(private AccountAccess $access, private LastSuperAdminGuard $guard, private ClockInterface $clock)
    {
    }

    public function __invoke(DeactivateUser $command): void
    {
        $this->access->assertGranted(Permission::InstanceUsersManage);
        $userId = UserId::fromString($command->userId);
        if ($this->access->isActor($userId)) {
            throw new SelfManagementForbidden();
        }

        $this->guard->lock();
        $user = $this->access->lock($userId);
        $this->guard->assertNotLast($user);
        $user->deactivate($this->clock->now());
    }
}
