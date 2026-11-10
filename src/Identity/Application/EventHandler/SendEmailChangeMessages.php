<?php

declare(strict_types=1);

namespace Identity\Application\EventHandler;

use Identity\Application\Port\EmailChangeSender;
use Identity\Domain\Event\EmailChanged;
use Identity\Domain\Event\EmailChangeRequested;
use Shared\Application\Bus\EventHandler;

final readonly class SendEmailChangeMessages implements EventHandler
{
    public function __construct(private EmailChangeSender $sender)
    {
    }

    public function __invoke(EmailChangeRequested|EmailChanged $event): void
    {
        if ($event instanceof EmailChangeRequested) {
            $this->sender->sendConfirmation($event->newEmail, $event->plainToken);

            return;
        }

        $this->sender->alertPreviousAddress($event->previousEmail);
    }
}
