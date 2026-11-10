<?php

declare(strict_types=1);

namespace Identity\Application\Command\Administration\Handler;

use Identity\Application\Command\Administration\ConfirmEmailChange;
use Identity\Domain\Model\EmailAlreadyRegistered;
use Identity\Domain\Model\EmailChangeLinkInvalid;
use Identity\Domain\Model\SuperAdminEmailLocked;
use Identity\Domain\Repository\EmailChangeRequestRepository;
use Identity\Domain\Repository\PasswordResetRequestRepository;
use Identity\Domain\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Locking\TransactionLock;
use Symfony\Component\Uid\Uuid;

/**
 * Checked again at the confirmation, under the lock of the new address: still free, account not deleted, not made
 * super admin since the request (SuperAdminEmailLocked).
 */
final readonly class ConfirmEmailChangeHandler implements CommandHandler
{
    public function __construct(
        private EmailChangeRequestRepository $requests,
        private UserRepository $users,
        private PasswordResetRequestRepository $passwordResets,
        private TransactionLock $lock,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ConfirmEmailChange $command): void
    {
        $request = $this->requests->find(Uuid::fromString($command->requestId)) ?? throw new EmailChangeLinkInvalid();
        $email = $request->newEmail();
        $this->lock->acquire('identity.email:'.$email->value());
        $user = $this->users->lockById($request->userId());
        if (null === $user || $user->status()->isDeleted()) {
            throw new EmailChangeLinkInvalid();
        }

        if ($user->isSuperAdmin()) {
            throw new SuperAdminEmailLocked();
        }

        $now = $this->clock->now();
        $request->consume($now);
        if (null !== $this->users->findByEmail($email)) {
            throw new EmailAlreadyRegistered();
        }

        // Reset and setup links went to the previous address: whoever still reads it must not choose the password of
        // the account once it answers to the new one.
        foreach ($this->passwordResets->findOpenFor($user->id()) as $open) {
            $open->invalidate($now);
        }

        $user->changeEmail($email);
    }
}
