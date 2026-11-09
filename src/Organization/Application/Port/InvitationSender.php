<?php

declare(strict_types=1);

namespace Organization\Application\Port;

/**
 * Queues the invitation email (link with the plain token); it leaves after the response.
 */
interface InvitationSender
{
    public function send(string $invitationId, string $email, #[\SensitiveParameter] string $token): void;
}
