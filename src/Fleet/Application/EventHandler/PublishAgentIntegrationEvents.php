<?php

declare(strict_types=1);

namespace Fleet\Application\EventHandler;

use Fleet\Domain\Event\AgentEnrolled;
use Shared\Application\Bus\EventBus;
use Shared\Application\Bus\EventHandler;
use Shared\Application\Contract\Fleet\AgentAwaitingApproval;

/**
 * Translates Fleet's internal facts into its published language: an agent waiting for approval (Notification).
 */
final readonly class PublishAgentIntegrationEvents implements EventHandler
{
    public function __construct(private EventBus $events)
    {
    }

    public function __invoke(AgentEnrolled $event): void
    {
        if ($event->pending) {
            $this->events->publish(new AgentAwaitingApproval($event->agentId, $event->teamId, $event->hostname));
        }
    }
}
