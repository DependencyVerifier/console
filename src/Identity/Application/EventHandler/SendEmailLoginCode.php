<?php

declare(strict_types=1);

namespace Identity\Application\EventHandler;

use Identity\Application\Port\LoginCodeSender;
use Identity\Domain\Event\EmailLoginCodeIssued;
use Identity\Domain\Model\UserId;
use Identity\Domain\Repository\UserRepository;
use Shared\Application\Bus\EventHandler;

/**
 * After the commit: the code leaves only for a code really stored.
 */
final readonly class SendEmailLoginCode implements EventHandler
{
    public function __construct(private UserRepository $users, private LoginCodeSender $sender)
    {
    }

    public function __invoke(EmailLoginCodeIssued $event): void
    {
        $user = $this->users->find(UserId::fromString($event->userId));
        if (null !== $user) {
            $this->sender->send($user, $event->plainCode);
        }
    }
}
