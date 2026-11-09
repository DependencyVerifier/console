<?php

declare(strict_types=1);

namespace Shared\Application\Audit;

/**
 * Lets a self-audited command (code verification) write its single entry, with the outcome it computed. Ordinary
 * commands never call it: the bus middlewares audit them.
 */
interface CommandAudit
{
    public function record(SelfAuditedCommand $command, Outcome $outcome): void;
}
