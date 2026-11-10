<?php

declare(strict_types=1);

namespace Identity\Application\Account;

use Identity\Application\Security\AccountAccess;
use Identity\Domain\Model\AccountNotAssignable;
use Identity\Domain\Model\EmailAddress;
use Identity\Domain\Model\UserId;
use Identity\Domain\Repository\UserRepository;
use Shared\Application\Contract\Identity\AccountProvisioning;
use Shared\Application\Contract\Identity\ProvisionedAccount;
use Shared\Application\Locking\TransactionLock;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;

final readonly class AccountProvisioner implements AccountProvisioning
{
    public function __construct(
        private AccountAccess $access,
        private UserRepository $users,
        private TransactionLock $lock,
        private AccountInvitation $invitation,
    ) {
    }

    public function teamAdminAccount(string $email, string $newUserId, string $setupRequestId): ProvisionedAccount
    {
        $this->access->assertGranted(Permission::InstanceUsersManage);
        $address = EmailAddress::of($email);
        $this->lock->acquire('identity.email:'.$address->value());

        $existing = $this->users->findByEmail($address);
        if (null !== $existing) {
            if (!$existing->maySetPassword()) {
                throw new AccountNotAssignable();
            }

            return new ProvisionedAccount($existing->id()->toString(), $address->value(), false);
        }

        $user = $this->invitation->invite(UserId::fromString($newUserId), $address, Uuid::fromString($setupRequestId));

        return new ProvisionedAccount($user->id()->toString(), $address->value(), true);
    }
}
