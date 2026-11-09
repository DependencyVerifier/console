<?php

declare(strict_types=1);

namespace Identity\Application\Port;

use Identity\Domain\Model\User;

/**
 * Sends the setup link of an account invited from /admin/users (choose your password, 7 days).
 */
interface AccountSetupSender
{
    public function send(User $user, #[\SensitiveParameter] string $token): void;
}
