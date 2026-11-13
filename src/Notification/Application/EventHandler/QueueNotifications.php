<?php

declare(strict_types=1);

namespace Notification\Application\EventHandler;

use Notification\Application\Command\Notify\NotifyAgentAwaitingApproval;
use Notification\Application\Command\Notify\NotifyFindingChange;
use Shared\Application\Bus\CommandBus;
use Shared\Application\Bus\EventHandler;
use Shared\Application\Contract\Exposure\FindingChanged;
use Shared\Application\Contract\Fleet\AgentAwaitingApproval;

/**
 * What the other contexts publish goes to the `notifications` queue: the computation never waits for a notification.
 */
final readonly class QueueNotifications implements EventHandler
{
    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(FindingChanged|AgentAwaitingApproval $event): void
    {
        $this->commands->dispatch($event instanceof FindingChanged
            ? new NotifyFindingChange($event->findingId, $event->teamId, $event->change, $event->at)
            : new NotifyAgentAwaitingApproval($event->agentId, $event->teamId, $event->hostname));
    }
}
