<?php

declare(strict_types=1);

namespace Exposure\Application\EventHandler;

use Exposure\Application\Command\Refresh\RequestExposureRefresh;
use Shared\Application\Bus\CommandBus;
use Shared\Application\Bus\EventHandler;
use Shared\Application\Contract\Inventory\InventoryChanged;
use Shared\Application\Contract\Vulnerability\AdvisoriesChanged;

/**
 * A new inventory changes what its team runs; a changed advisory may concern any team (which ones is not worth
 * finding out: every inventoried team is computed again, once per burst).
 */
final readonly class RequestRefreshOnChange implements EventHandler
{
    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(InventoryChanged|AdvisoriesChanged $event): void
    {
        $this->commands->dispatch(new RequestExposureRefresh($event instanceof InventoryChanged ? $event->teamId : null));
    }
}
