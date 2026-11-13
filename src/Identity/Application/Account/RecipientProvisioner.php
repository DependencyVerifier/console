<?php

declare(strict_types=1);

namespace Identity\Application\Account;

use Identity\Domain\Model\AccountNotAssignable;
use Identity\Domain\Model\EmailAddress;
use Identity\Domain\Model\User;
use Identity\Domain\Model\UserId;
use Identity\Domain\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Contract\Identity\RecipientAccount;
use Shared\Application\Contract\Identity\RecipientProvisioning;
use Shared\Application\Locking\TransactionLock;

final readonly class RecipientProvisioner implements RecipientProvisioning
{
    public function __construct(private UserRepository $users, private TransactionLock $lock, private ClockInterface $clock)
    {
    }

    public function recipientAccount(string $email, string $newUserId): RecipientAccount
    {
        $address = EmailAddress::of($email);
        $this->lock->acquire('identity.email:'.$address->value());

        $existing = $this->users->findByEmail($address);
        if (null !== $existing) {
            if (!$existing->status()->isActive()) {
                throw new AccountNotAssignable();
            }

            return new RecipientAccount($existing->id()->toString(), !$existing->isNotificationOnly());
        }

        $user = User::notificationOnly(UserId::fromString($newUserId), $address, $this->clock->now());
        $this->users->add($user);

        return new RecipientAccount($user->id()->toString(), false);
    }
}
