<?php

declare(strict_types=1);

namespace Notification\Application\Port;

/**
 * The confirmation link of a recipient, sent after the commit (with the other deferred emails).
 */
interface RecipientConfirmationSender
{
    public function send(string $email, string $teamName, #[\SensitiveParameter] string $token): void;
}
