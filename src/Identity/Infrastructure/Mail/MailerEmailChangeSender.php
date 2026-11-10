<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Mail;

use Identity\Application\Port\EmailChangeSender;
use Identity\UI\Controller\RouteCollection;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class MailerEmailChangeSender implements EmailChangeSender
{
    public function __construct(private IdentityEmails $emails, private UrlGeneratorInterface $urls)
    {
    }

    public function sendConfirmation(string $newEmail, #[\SensitiveParameter] string $token): void
    {
        $this->emails->queueTo($newEmail, 'email_change', 'identity.email_change.subject', [
            'url' => $this->urls->generate(RouteCollection::EMAIL_CHANGE_LANDING->prefixed(), ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
            'validity_hours' => 24,
        ]);
    }

    public function alertPreviousAddress(string $previousEmail): void
    {
        $this->emails->queueTo($previousEmail, 'security_alert', 'identity.alert.subject', ['alert' => 'email_changed']);
    }
}
