<?php

declare(strict_types=1);

namespace Notification\Infrastructure\Mail;

use Notification\Application\Port\RecipientConfirmationSender;
use Notification\Domain\Model\RecipientInvitation;
use Notification\UI\Controller\RouteCollection;
use Shared\Infrastructure\Mail\DeferredMailQueue;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class MailerRecipientConfirmationSender implements RecipientConfirmationSender
{
    public function __construct(
        private DeferredMailQueue $queue,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        #[Autowire(env: 'MAILER_FROM')]
        private string $from,
    ) {
    }

    public function send(string $email, string $teamName, #[\SensitiveParameter] string $token): void
    {
        $this->queue->queue(new TemplatedEmail()
            ->from(new Address($this->from, 'DependencyVerifier'))
            ->to($email)
            ->subject($this->translator->trans('notification.confirm.subject', ['team' => $teamName], 'email'))
            ->htmlTemplate('email/notification/confirm.html.twig')
            ->textTemplate('email/notification/confirm.txt.twig')
            ->context([
                'team' => $teamName,
                'url' => $this->urls->generate(RouteCollection::CONFIRM_RECIPIENT->prefixed(), ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
                'validity_days' => RecipientInvitation::VALIDITY_DAYS,
            ]));
    }
}
