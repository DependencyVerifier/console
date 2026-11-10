<?php

declare(strict_types=1);

namespace Organization\Infrastructure\Mail;

use Organization\Application\Port\TeamAdminAppointmentSender;
use Organization\UI\Controller\RouteCollection;
use Shared\Infrastructure\Mail\DeferredMailQueue;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class MailerTeamAdminAppointmentSender implements TeamAdminAppointmentSender
{
    public function __construct(
        private DeferredMailQueue $queue,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        #[Autowire(env: 'MAILER_FROM')]
        private string $from,
    ) {
    }

    public function send(string $email, string $teamId, string $teamName): void
    {
        $this->queue->queue(new TemplatedEmail()
            ->from(new Address($this->from, 'DependencyVerifier'))
            ->to($email)
            ->subject($this->translator->trans('organization.team_admin_appointed.subject', ['team' => $teamName], 'email'))
            ->htmlTemplate('email/organization/team_admin_appointed.html.twig')
            ->textTemplate('email/organization/team_admin_appointed.txt.twig')
            ->context([
                'team' => $teamName,
                'url' => $this->urls->generate(RouteCollection::TEAM->prefixed(), ['teamId' => $teamId], UrlGeneratorInterface::ABSOLUTE_URL),
            ]));
    }
}
