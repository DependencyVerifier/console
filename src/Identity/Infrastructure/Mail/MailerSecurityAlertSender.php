<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Mail;

use Identity\Application\Port\SecurityAlert;
use Identity\Application\Port\SecurityAlertSender;
use Identity\Domain\Model\User;

/**
 * Decision 26: no action link, only "if this was not you, contact the administrator of the instance".
 */
final readonly class MailerSecurityAlertSender implements SecurityAlertSender
{
    public function __construct(private IdentityEmails $emails)
    {
    }

    public function send(User $user, SecurityAlert $alert): void
    {
        $this->emails->queue($user, 'security_alert', 'identity.alert.subject', ['alert' => $alert->value]);
    }
}
