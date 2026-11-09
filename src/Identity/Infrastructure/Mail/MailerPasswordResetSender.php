<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Mail;

use Identity\Application\Port\PasswordResetSender;
use Identity\Domain\Model\User;
use Identity\UI\Controller\RouteCollection;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class MailerPasswordResetSender implements PasswordResetSender
{
    public function __construct(private IdentityEmails $emails, private UrlGeneratorInterface $urls)
    {
    }

    public function send(User $user, #[\SensitiveParameter] string $token): void
    {
        $this->emails->queue($user, 'password_reset', 'identity.password_reset.subject', [
            'url' => $this->urls->generate(RouteCollection::PASSWORD_RESET_LANDING->prefixed(), ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
            'validity_minutes' => 30,
        ]);
    }
}
