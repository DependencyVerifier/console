<?php

declare(strict_types=1);

namespace Identity\Application\Command\Password\Handler;

use Identity\Application\Command\Password\ChangePassword;
use Identity\Application\Port\PasswordHasher;
use Identity\Application\Security\AccountAccess;
use Identity\Domain\Model\PasswordPolicy;
use Identity\Domain\Model\UserId;
use Shared\Application\Bus\CommandHandler;

final readonly class ChangePasswordHandler implements CommandHandler
{
    public function __construct(private AccountAccess $access, private PasswordHasher $hasher)
    {
    }

    public function __invoke(ChangePassword $command): void
    {
        $userId = UserId::fromString($command->userId);
        $this->access->assertSelf($userId);
        $user = $this->access->lock($userId);
        PasswordPolicy::check($command->newPassword, $user->email());
        $user->changePassword($this->hasher->hash($command->newPassword));
    }
}
