<?php

declare(strict_types=1);

namespace Identity\Application\Command\Account\Handler;

use Identity\Application\Command\Account\CreateSuperAdmin;
use Identity\Application\Port\PasswordHasher;
use Identity\Domain\Model\EmailAddress;
use Identity\Domain\Model\EmailAlreadyRegistered;
use Identity\Domain\Model\PasswordPolicy;
use Identity\Domain\Model\User;
use Identity\Domain\Model\UserId;
use Identity\Domain\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Audit\ActorType;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Locking\TransactionLock;
use Shared\Application\Security\CurrentActor;

/**
 * CLI only (`app:user:create-super-admin`): the operator of the instance, outside any web session.
 */
final readonly class CreateSuperAdminHandler implements CommandHandler
{
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $hasher,
        private TransactionLock $lock,
        private CurrentActor $currentActor,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CreateSuperAdmin $command): void
    {
        if (ActorType::Cli !== $this->currentActor->actor()->type) {
            throw PermissionDenied::for('cli');
        }

        $email = EmailAddress::of($command->email);
        PasswordPolicy::check($command->password, $email);
        $this->lock->acquire('identity.email:'.$email->value());
        if (null !== $this->users->findByEmail($email)) {
            throw new EmailAlreadyRegistered();
        }

        $this->users->add(User::createSuperAdmin(UserId::fromString($command->userId), $email, $this->hasher->hash($command->password), $this->clock->now()));
    }
}
