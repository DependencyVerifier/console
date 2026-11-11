<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * One SCAN_NOW per agent every 5 minutes (protocol §10).
 */
final class ScanAlreadyRequested extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('scan_now_rate');
    }
}
