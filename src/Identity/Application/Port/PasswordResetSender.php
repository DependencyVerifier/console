<?php

declare(strict_types=1);

namespace Identity\Application\Port;

use Identity\Domain\Model\User;

interface PasswordResetSender
{
    public function send(User $user, #[\SensitiveParameter] string $token): void;
}
