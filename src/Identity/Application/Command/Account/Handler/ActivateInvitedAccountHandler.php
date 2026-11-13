<?php

declare(strict_types=1);

namespace Identity\Application\Command\Account\Handler;

use Identity\Application\Command\Account\ActivateInvitedAccount;
use Identity\Application\Port\PasswordHasher;
use Identity\Domain\Model\AccountAlreadyActive;
use Identity\Domain\Model\EmailAddress;
use Identity\Domain\Model\PasswordPolicy;
use Identity\Domain\Model\User;
use Identity\Domain\Model\UserId;
use Identity\Domain\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Organization\FindPendingInvitation;
use Shared\Application\Locking\TransactionLock;

/**
 * Bound to a pending invitation (its email), under an advisory lock on that email: two concurrent activations cannot
 * both create the account (the unique email index is the safety net). An existing account is refused: its holder
 * signs in and accepts; but a notification-only account (it cannot sign in) is promoted, keeping its memberships.
 */
final readonly class ActivateInvitedAccountHandler implements CommandHandler
{
    public function __construct(
        private QueryBus $queries,
        private UserRepository $users,
        private PasswordHasher $hasher,
        private TransactionLock $lock,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ActivateInvitedAccount $command): void
    {
        $invitation = $this->queries->ask(FindPendingInvitation::byId($command->invitationId));
        $email = EmailAddress::of($invitation->email);
        PasswordPolicy::check($command->password, $email);
        $this->lock->acquire('identity.email:'.$email->value());
        $existing = $this->users->findByEmail($email);
        if (null !== $existing) {
            // A notification-only account (active, never a password) is promoted, once every refusal is behind.
            $promotable = $existing->status()->isActive() && $existing->isNotificationOnly() && $existing->awaitsPassword();

            // An account invited from /admin/users that never chose its password completes its activation here.
            if (!$existing->awaitsPassword() || !($promotable || $existing->maySetPassword())) {
                throw new AccountAlreadyActive();
            }

            if ($promotable) {
                $existing->promote();
            }

            $existing->choosePassword($this->hasher->hash($command->password));

            return;
        }

        $this->users->add(User::activateFromInvitation(UserId::fromString($command->userId), $email, $this->hasher->hash($command->password), $this->clock->now()));
    }
}
