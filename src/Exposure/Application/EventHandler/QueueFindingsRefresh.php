<?php

declare(strict_types=1);

namespace Exposure\Application\EventHandler;

use Exposure\Application\Command\Refresh\RefreshFindings;
use Exposure\Domain\Event\ExposureRefreshRequested;
use Shared\Application\Bus\CommandBus;
use Shared\Application\Bus\EventHandler;

/**
 * The computation itself runs in the `matching` queue.
 */
final readonly class QueueFindingsRefresh implements EventHandler
{
    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(ExposureRefreshRequested $event): void
    {
        $this->commands->dispatch(new RefreshFindings($event->teamId));
    }
}
