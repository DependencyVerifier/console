<?php

declare(strict_types=1);

namespace Identity\Application\Command\Administration\Handler;

use Identity\Application\Account\AccountInvitation;
use Identity\Application\Command\Administration\InviteUser;
use Identity\Application\Security\AccountAccess;
use Identity\Domain\Model\EmailAddress;
use Identity\Domain\Model\EmailAlreadyRegistered;
use Identity\Domain\Model\UserId;
use Identity\Domain\Repository\UserRepository;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Locking\TransactionLock;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;

final readonly class InviteUserHandler implements CommandHandler
{
    public function __construct(
        private AccountAccess $access,
        private UserRepository $users,
        private TransactionLock $lock,
        private AccountInvitation $invitation,
    ) {
    }

    public function __invoke(InviteUser $command): void
    {
        $this->access->assertGranted(Permission::InstanceUsersManage);
        $email = EmailAddress::of($command->email);
        $this->lock->acquire('identity.email:'.$email->value());
        if (null !== $this->users->findByEmail($email)) {
            throw new EmailAlreadyRegistered();
        }

        $this->invitation->invite(UserId::fromString($command->userId), $email, Uuid::fromString($command->requestId));
    }
}
