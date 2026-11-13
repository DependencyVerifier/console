<?php

declare(strict_types=1);

namespace Notification\Application\Port;

/**
 * The confirmation link of an invited recipient, sent after the commit (with the other deferred emails). The same
 * email for every address, whatever account it may have.
 */
interface RecipientConfirmationSender
{
    public function send(string $email, string $teamName, #[\SensitiveParameter] string $token): void;
}
