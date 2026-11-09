<?php

declare(strict_types=1);

namespace Tests\Identity\Support;

use Shared\Application\Audit\CommandAudit;
use Shared\Application\Audit\Outcome;
use Shared\Application\Audit\SelfAuditedCommand;

final class RecordingAudit implements CommandAudit
{
    /** @var list<array{SelfAuditedCommand, Outcome}> */
    public array $entries = [];

    public function record(SelfAuditedCommand $command, Outcome $outcome): void
    {
        $this->entries[] = [$command, $outcome];
    }
}
