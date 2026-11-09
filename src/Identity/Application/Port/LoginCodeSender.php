<?php

declare(strict_types=1);

namespace Identity\Application\Port;

use Identity\Domain\Model\User;

/**
 * Queues the email login code; it leaves once the response is sent (kernel.terminate).
 */
interface LoginCodeSender
{
    public function send(User $user, #[\SensitiveParameter] string $code): void;
}
