<?php

declare(strict_types=1);

namespace Inventory\Application\Command\Maintenance;

/**
 * What a maintenance run did, filled by its handler for the CLI.
 */
final class MaintenanceReport
{
    public int $discarded = 0;
    public int $purgedHistory = 0;
    public int $collectedBlobs = 0;
}
