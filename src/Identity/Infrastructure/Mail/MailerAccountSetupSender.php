<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Mail;

use Identity\Application\Port\AccountSetupSender;
use Identity\Domain\Model\User;
use Identity\UI\Controller\RouteCollection;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The link lands on the password reset pages: the first password and a reset are the same gesture.
 */
final readonly class MailerAccountSetupSender implements AccountSetupSender
{
    public function __construct(private IdentityEmails $emails, private UrlGeneratorInterface $urls)
    {
    }

    public function send(User $user, #[\SensitiveParameter] string $token): void
    {
        $this->emails->queue($user, 'account_setup', 'identity.account_setup.subject', [
            'url' => $this->urls->generate(RouteCollection::PASSWORD_RESET_LANDING->prefixed(), ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
            'validity_days' => 7,
        ]);
    }
}
