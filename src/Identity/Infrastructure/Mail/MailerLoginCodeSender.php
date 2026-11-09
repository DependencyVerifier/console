<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Mail;

use Identity\Application\Port\LoginCodeSender;
use Identity\Domain\Model\EmailLoginCode;
use Identity\Domain\Model\User;

final readonly class MailerLoginCodeSender implements LoginCodeSender
{
    public function __construct(private IdentityEmails $emails)
    {
    }

    public function send(User $user, #[\SensitiveParameter] string $code): void
    {
        $this->emails->queue($user, 'login_code', 'identity.login_code.subject', [
            'code' => $code,
            'validity_minutes' => 10,
            'max_attempts' => EmailLoginCode::MAX_ATTEMPTS,
        ]);
    }
}
