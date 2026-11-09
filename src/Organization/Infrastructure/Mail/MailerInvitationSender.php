<?php

declare(strict_types=1);

namespace Organization\Infrastructure\Mail;

use Organization\Application\Port\InvitationSender;
use Shared\Infrastructure\Mail\DeferredMailQueue;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The invitation link points to Identity's landing page (`identity_invitation_landing`), which exchanges the token for
 * the invitation id in the session.
 */
final readonly class MailerInvitationSender implements InvitationSender
{
    public function __construct(
        private DeferredMailQueue $queue,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        #[Autowire(env: 'MAILER_FROM')]
        private string $from,
    ) {
    }

    public function send(string $invitationId, string $email, #[\SensitiveParameter] string $token): void
    {
        $this->queue->queue(new TemplatedEmail()
            ->from(new Address($this->from, 'DependencyVerifier'))
            ->to($email)
            ->subject($this->translator->trans('organization.invitation.subject', domain: 'email'))
            ->htmlTemplate('email/organization/invitation.html.twig')
            ->textTemplate('email/organization/invitation.txt.twig')
            ->context([
                'url' => $this->urls->generate('identity_invitation_landing', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
                'validity_days' => 7,
            ]));
    }
}
