<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * The console never sends an interval below the team's minScanIntervalMinutes (protocol §7.3).
 */
final class ScanIntervalBelowMinimum extends BusinessRuleViolated
{
    public function __construct(public readonly int $minimum)
    {
        parent::__construct('scan_interval_below_minimum', \sprintf('The team scans every %d minutes at most.', $minimum));
    }
}
