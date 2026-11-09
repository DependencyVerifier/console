<?php

declare(strict_types=1);

namespace Identity\Application\Command\Administration\Handler;

use Identity\Application\Command\Administration\InviteUser;
use Identity\Application\Security\AccountAccess;
use Identity\Domain\Model\EmailAddress;
use Identity\Domain\Model\EmailAlreadyRegistered;
use Identity\Domain\Model\PasswordResetRequest;
use Identity\Domain\Model\User;
use Identity\Domain\Model\UserId;
use Identity\Domain\Repository\PasswordResetRequestRepository;
use Identity\Domain\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Crypto\TokenGenerator;
use Shared\Application\Crypto\TokenHasher;
use Shared\Application\Locking\TransactionLock;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;

final readonly class InviteUserHandler implements CommandHandler
{
    public function __construct(
        private AccountAccess $access,
        private UserRepository $users,
        private PasswordResetRequestRepository $requests,
        private TransactionLock $lock,
        private TokenGenerator $tokens,
        private TokenHasher $hasher,
        private ClockInterface $clock,
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

        $now = $this->clock->now();
        $user = User::invite(UserId::fromString($command->userId), $email, $now);
        $this->users->add($user);
        $token = $this->tokens->urlToken();
        $this->requests->add(PasswordResetRequest::issueAccountSetup(Uuid::fromString($command->requestId), $user->id(), $token, $this->hasher->hash($token), $now));
    }
}
