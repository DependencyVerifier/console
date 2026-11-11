<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Maintenance;

/**
 * What a maintenance run did, filled by its handler for the CLI.
 */
final class MaintenanceReport
{
    public int $inactive = 0;
    public int $revoked = 0;
    public int $expiredOrders = 0;
    public int $purgedNonces = 0;
}
