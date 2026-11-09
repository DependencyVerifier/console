<?php

declare(strict_types=1);

namespace Identity\Application\EventHandler;

use Identity\Domain\Event\UserDeactivated;
use Identity\Domain\Event\UserReactivated;
use Shared\Application\Bus\EventBus;
use Shared\Application\Bus\EventHandler;
use Shared\Application\Contract\Identity\AccountDeactivated;
use Shared\Application\Contract\Identity\AccountReactivated;

/**
 * Translates Identity's internal facts into its published language: Organization only consumes the contracts.
 */
final readonly class PublishAccountIntegrationEvents implements EventHandler
{
    public function __construct(private EventBus $events)
    {
    }

    public function __invoke(UserDeactivated|UserReactivated $event): void
    {
        $this->events->publish($event instanceof UserDeactivated
            ? new AccountDeactivated($event->userId)
            : new AccountReactivated($event->userId));
    }
}
