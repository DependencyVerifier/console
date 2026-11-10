<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Mail;

use Identity\Domain\Model\User;
use Shared\Infrastructure\Mail\DeferredMailQueue;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds and queues an Identity email (HTML + text templates under templates/email/identity/). It leaves after the
 * response, from the deferred mail queue.
 */
final readonly class IdentityEmails
{
    public function __construct(
        private DeferredMailQueue $queue,
        private TranslatorInterface $translator,
        #[Autowire(env: 'MAILER_FROM')]
        private string $from,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function queue(User $user, string $template, string $subjectKey, array $context = []): void
    {
        $this->queueTo($user->email()->value(), $template, $subjectKey, $context);
    }

    /**
     * To another address than the account's (the new address of an email change, or the previous one).
     *
     * @param array<string, mixed> $context
     */
    public function queueTo(string $address, string $template, string $subjectKey, array $context = []): void
    {
        $this->queue->queue(new TemplatedEmail()
            ->from(new Address($this->from, 'DependencyVerifier'))
            ->to($address)
            ->subject($this->translator->trans($subjectKey, domain: 'email'))
            ->htmlTemplate(\sprintf('email/identity/%s.html.twig', $template))
            ->textTemplate(\sprintf('email/identity/%s.txt.twig', $template))
            ->context($context));
    }
}
