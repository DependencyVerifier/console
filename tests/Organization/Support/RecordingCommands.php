<?php

declare(strict_types=1);

namespace Tests\Organization\Support;

use Shared\Application\Bus\Command;
use Shared\Application\Bus\CommandBus;

final class RecordingCommands implements CommandBus
{
    /** @var list<Command> */
    public array $dispatched = [];

    public function dispatch(Command $command): void
    {
        $this->dispatched[] = $command;
    }
}
