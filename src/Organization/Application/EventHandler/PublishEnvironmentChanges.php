<?php

declare(strict_types=1);

namespace Organization\Application\EventHandler;

use Organization\Domain\Event\EnvironmentProductionChanged;
use Shared\Application\Bus\EventBus;
use Shared\Application\Bus\EventHandler;
use Shared\Application\Contract\Organization\EnvironmentChanged;

/**
 * Tells the other contexts that an environment changed (Exposure copies whether it is a production one).
 */
final readonly class PublishEnvironmentChanges implements EventHandler
{
    public function __construct(private EventBus $events)
    {
    }

    public function __invoke(EnvironmentProductionChanged $event): void
    {
        $this->events->publish(new EnvironmentChanged($event->teamId, $event->environmentId));
    }
}
