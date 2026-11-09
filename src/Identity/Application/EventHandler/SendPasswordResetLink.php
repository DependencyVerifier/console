<?php

declare(strict_types=1);

namespace Identity\Application\EventHandler;

use Identity\Application\Port\PasswordResetSender;
use Identity\Domain\Event\PasswordResetRequested;
use Identity\Domain\Model\UserId;
use Identity\Domain\Repository\UserRepository;
use Shared\Application\Bus\EventHandler;

final readonly class SendPasswordResetLink implements EventHandler
{
    public function __construct(private UserRepository $users, private PasswordResetSender $sender)
    {
    }

    public function __invoke(PasswordResetRequested $event): void
    {
        $user = $this->users->find(UserId::fromString($event->userId));
        if (null !== $user) {
            $this->sender->send($user, $event->plainToken);
        }
    }
}
