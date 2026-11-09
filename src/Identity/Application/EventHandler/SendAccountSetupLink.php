<?php

declare(strict_types=1);

namespace Identity\Application\EventHandler;

use Identity\Application\Port\AccountSetupSender;
use Identity\Domain\Event\AccountSetupRequested;
use Identity\Domain\Model\UserId;
use Identity\Domain\Repository\UserRepository;
use Shared\Application\Bus\EventHandler;

final readonly class SendAccountSetupLink implements EventHandler
{
    public function __construct(private UserRepository $users, private AccountSetupSender $sender)
    {
    }

    public function __invoke(AccountSetupRequested $event): void
    {
        $user = $this->users->find(UserId::fromString($event->userId));
        if (null !== $user) {
            $this->sender->send($user, $event->plainToken);
        }
    }
}
