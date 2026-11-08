<?php

declare(strict_types=1);

namespace Shared\Application\Bus;

/**
 * Implemented by command handlers (an `__invoke(SomeCommand $command): void` method).
 * Routed to the command bus by `config/services.yaml` (`_instanceof`).
 */
interface CommandHandler
{
}
