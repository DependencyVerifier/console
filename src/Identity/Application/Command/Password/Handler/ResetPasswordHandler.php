<?php

declare(strict_types=1);

namespace Identity\Application\Command\Password\Handler;

use Identity\Application\Command\Password\ResetPassword;
use Identity\Application\Port\PasswordHasher;
use Identity\Domain\Model\PasswordPolicy;
use Identity\Domain\Model\PasswordResetLinkInvalid;
use Identity\Domain\Repository\PasswordResetRequestRepository;
use Identity\Domain\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Consumes the link, then sets the password: every session and trusted device is revoked, the second factor stays
 * required at the next login.
 */
final readonly class ResetPasswordHandler implements CommandHandler
{
    public function __construct(
        private PasswordResetRequestRepository $requests,
        private UserRepository $users,
        private PasswordHasher $hasher,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ResetPassword $command): void
    {
        $request = $this->requests->find(Uuid::fromString($command->requestId)) ?? throw new PasswordResetLinkInvalid();
        $user = $this->users->lockById($request->userId());
        if (null === $user || !$user->maySetPassword()) {
            throw new PasswordResetLinkInvalid();
        }

        $request->consume($this->clock->now());
        PasswordPolicy::check($command->newPassword, $user->email());
        $user->choosePassword($this->hasher->hash($command->newPassword));
    }
}
