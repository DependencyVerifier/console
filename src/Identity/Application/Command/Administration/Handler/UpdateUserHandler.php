<?php

declare(strict_types=1);

namespace Identity\Application\Command\Administration\Handler;

use Identity\Application\Command\Administration\UpdateUser;
use Identity\Application\Security\AccountAccess;
use Identity\Domain\Model\EmailAddress;
use Identity\Domain\Model\EmailAlreadyRegistered;
use Identity\Domain\Model\EmailChangeRequest;
use Identity\Domain\Model\User;
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

final readonly class UpdateUserHandler implements CommandHandler
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

    public function __invoke(UpdateUser $command): void
    {
        $this->access->assertGranted(Permission::InstanceUsersManage);
        $user = $this->access->lock(UserId::fromString($command->userId));
        $user->rename($command->displayName);

        $email = EmailAddress::of($command->email);
        if (!$email->equals($user->email())) {
            $this->requestChange($user, $email, Uuid::fromString($command->emailChangeId));
        }
    }

    private function requestChange(User $user, EmailAddress $email, Uuid $requestId): void
    {
        $this->lock->acquire('identity.email:'.$email->value());
        if (null !== $this->users->findByEmail($email)) {
            throw new EmailAlreadyRegistered();
        }

        $now = $this->clock->now();
        foreach ($this->requests->findOpenFor($user->id()) as $previous) {
            $previous->invalidate($now);
        }

        $token = $this->tokens->urlToken();
        $this->requests->add(EmailChangeRequest::issue($requestId, $user->id(), $email, $token, $this->hasher->hash($token), $now));
    }
}
