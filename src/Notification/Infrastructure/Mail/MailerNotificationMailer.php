<?php

declare(strict_types=1);

namespace Notification\Infrastructure\Mail;

use Notification\Application\Port\NoticeView;
use Notification\Application\Port\NotificationMailer;
use Notification\Domain\Model\Frequency;
use Notification\UI\Controller\RouteCollection;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sent at once (never through DeferredMailQueue: it empties on terminate, never in the worker). Each email carries
 * its unsubscribe link (signed, no login) and the one-click unsubscribe headers (RFC 8058).
 */
final readonly class MailerNotificationMailer implements NotificationMailer
{
    // The pages of the other contexts, by route name (no import of their UI).
    private const string FINDING_ROUTE = 'exposure_finding';
    private const string AGENTS_ROUTE = 'fleet_team_agents';

    public function __construct(
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urls,
        private UriSigner $signer,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        #[Autowire(env: 'MAILER_FROM')]
        private string $from,
    ) {
    }

    public function notice(string $email, NoticeView $notice): ?string
    {
        $item = $this->item($notice);

        return $this->send($email, $this->translator->trans('notification.notice.subject', ['event' => $notice->event, 'title' => $item['title'], 'team' => $notice->teamName], 'email'), 'notice', [
            'item' => $item,
            'unsubscribe' => $item['unsubscribe'],
        ], $item['unsubscribe']);
    }

    public function digest(string $email, Frequency $frequency, array $notices): ?string
    {
        $items = array_map($this->item(...), $notices);
        $unsubscribes = [];
        foreach ($items as $item) {
            $unsubscribes[$item['unsubscribe']] ??= ['url' => $item['unsubscribe'], 'team' => $item['team']];
        }

        return $this->send($email, $this->translator->trans('notification.digest.subject', ['frequency' => $frequency->value, 'count' => \count($items)], 'email'), 'digest', [
            'frequency' => $frequency->value,
            'items' => $items,
            'unsubscribes' => array_values($unsubscribes),
        ], null);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function send(string $email, string $subject, string $template, array $context, ?string $unsubscribe): ?string
    {
        $message = new TemplatedEmail()
            ->from(new Address($this->from, 'DependencyVerifier'))
            ->to($email)
            ->subject($subject)
            ->htmlTemplate('email/notification/'.$template.'.html.twig')
            ->textTemplate('email/notification/'.$template.'.txt.twig')
            ->context($context);
        if (null !== $unsubscribe) {
            $message->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$unsubscribe.'>');
            $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        }

        try {
            $this->mailer->send($message);
        } catch (\Throwable $failure) {
            $this->logger->error('A notification email could not be sent.', ['exception_class' => $failure::class]);

            return 'Mailer ('.new \ReflectionClass($failure)->getShortName().')';
        }

        return null;
    }

    /**
     * What a template shows of a notice: its event, a title, where it happened, a link, the unsubscribe link.
     *
     * @return array{event: string, title: string, team: string, subject: ?string, details: array<mixed>, url: string, unsubscribe: string, occurred_at: \DateTimeImmutable}
     */
    private function item(NoticeView $notice): array
    {
        $content = $notice->content;
        $finding = \is_array($content['finding'] ?? null) ? $content['finding'] : [];
        $agent = \is_array($content['agent'] ?? null) ? $content['agent'] : [];
        $subject = \is_array($content['subject'] ?? null) ? $content['subject'] : [];
        $findingId = \is_string($finding['id'] ?? null) ? $finding['id'] : null;
        $url = null === $findingId
            ? $this->urls->generate(self::AGENTS_ROUTE, ['teamId' => $notice->teamId], UrlGeneratorInterface::ABSOLUTE_URL)
            : $this->urls->generate(self::FINDING_ROUTE, ['teamId' => $notice->teamId, 'findingId' => $findingId], UrlGeneratorInterface::ABSOLUTE_URL);

        return [
            'event' => $notice->event,
            'title' => array_find([$finding['title'] ?? null, $agent['hostname'] ?? null], static fn (mixed $value): bool => \is_string($value)) ?? '',
            'team' => $notice->teamName,
            'subject' => \is_string($subject['name'] ?? null) ? $subject['name'] : null,
            'details' => $finding + $agent + (\is_array($content['acceptance'] ?? null) ? ['expires_on' => $content['acceptance']['expires_on'] ?? null] : []),
            'url' => $url,
            'unsubscribe' => $this->signer->sign($this->urls->generate(RouteCollection::UNSUBSCRIBE->prefixed(), ['subscription' => $notice->subscriptionId], UrlGeneratorInterface::ABSOLUTE_URL)),
            'occurred_at' => $notice->occurredAt,
        ];
    }
}
