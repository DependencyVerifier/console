<?php

declare(strict_types=1);

namespace Identity\Application\Account;

use Identity\Domain\Model\AccountNotAssignable;
use Identity\Domain\Model\EmailAddress;
use Identity\Domain\Model\RecipientSignsIn;
use Identity\Domain\Model\User;
use Identity\Domain\Model\UserId;
use Identity\Domain\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Contract\Identity\ProvisionedAccount;
use Shared\Application\Contract\Identity\RecipientProvisioning;
use Shared\Application\Locking\TransactionLock;
use Shared\Domain\Authorization\Permission;

final readonly class RecipientProvisioner implements RecipientProvisioning
{
    public function __construct(private UserRepository $users, private TransactionLock $lock, private ClockInterface $clock)
    {
    }

    public function notificationAccount(string $email, string $newUserId): ProvisionedAccount
    {
        $address = EmailAddress::of($email);
        $this->lock->acquire('identity.email:'.$address->value());

        $existing = $this->users->findByEmail($address);
        if (null !== $existing) {
            if (!$existing->status()->isActive()) {
                throw new AccountNotAssignable();
            }

            if ($existing->privileges()->has(Permission::AccountLogin)) {
                throw new RecipientSignsIn();
            }

            return new ProvisionedAccount($existing->id()->toString(), $address->value(), false);
        }

        $user = User::notificationOnly(UserId::fromString($newUserId), $address, $this->clock->now());
        $this->users->add($user);

        return new ProvisionedAccount($user->id()->toString(), $address->value(), true);
    }
}
