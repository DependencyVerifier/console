<?php

declare(strict_types=1);

namespace Identity\Application\Command\Administration\Handler;

use Identity\Application\Command\Administration\ChangeUserEmail;
use Identity\Application\Security\AccountAccess;
use Identity\Domain\Model\EmailAddress;
use Identity\Domain\Model\EmailAlreadyRegistered;
use Identity\Domain\Model\EmailChangeRequest;
use Identity\Domain\Model\SuperAdminEmailLocked;
use Identity\Domain\Model\UserId;
use Identity\Domain\Repository\EmailChangeRequestRepository;
use Identity\Domain\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Crypto\TokenGenerator;
use Shared\Application\Crypto\TokenHasher;
use Shared\Application\Locking\TransactionLock;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;

final readonly class ChangeUserEmailHandler implements CommandHandler
{
    public function __construct(
        private AccountAccess $access,
        private UserRepository $users,
        private EmailChangeRequestRepository $requests,
        private TransactionLock $lock,
        private TokenGenerator $tokens,
        private TokenHasher $hasher,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ChangeUserEmail $command): void
    {
        $this->access->assertGranted(Permission::InstanceUsersManage);
        $user = $this->access->lock(UserId::fromString($command->userId));
        if ($user->isSuperAdmin()) {
            throw new SuperAdminEmailLocked();
        }

        $email = EmailAddress::of($command->email);
        if ($email->equals($user->email())) {
            return;
        }

        $this->lock->acquire('identity.email:'.$email->value());
        if (null !== $this->users->findByEmail($email)) {
            throw new EmailAlreadyRegistered();
        }

        $now = $this->clock->now();
        foreach ($this->requests->findOpenFor($user->id()) as $previous) {
            $previous->invalidate($now);
        }

        $token = $this->tokens->urlToken();
        $this->requests->add(EmailChangeRequest::issue(Uuid::fromString($command->emailChangeId), $user->id(), $email, $token, $this->hasher->hash($token), $now));
    }
}
