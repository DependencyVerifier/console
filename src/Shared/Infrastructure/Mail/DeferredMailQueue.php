<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Mail;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Emails queued during a request leave in kernel.terminate (console.terminate for the CLI), once the response is
 * sent: an existing and an unknown account answer in the same time, and nothing leaves for a rolled back effect
 * (senders are called by event handlers, after the commit). A failing send is logged without its content.
 */
final class DeferredMailQueue implements ResetInterface
{
    /** @var list<Email> */
    private array $queue = [];

    public function __construct(private readonly MailerInterface $mailer, private readonly LoggerInterface $logger)
    {
    }

    public function queue(Email $email): void
    {
        $this->queue[] = $email;
    }

    /**
     * @return list<Email>
     */
    public function pending(): array
    {
        return $this->queue;
    }

    #[AsEventListener(KernelEvents::TERMINATE)]
    #[AsEventListener(ConsoleEvents::TERMINATE)]
    public function flush(): void
    {
        $queue = $this->queue;
        $this->queue = [];
        foreach ($queue as $email) {
            try {
                $this->mailer->send($email);
            } catch (\Throwable $failure) {
                $this->logger->error('An email could not be sent.', ['exception_class' => $failure::class]);
            }
        }
    }

    public function reset(): void
    {
        $this->queue = [];
    }
}
