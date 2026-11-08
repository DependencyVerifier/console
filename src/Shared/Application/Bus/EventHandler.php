<?php

declare(strict_types=1);

namespace Shared\Application\Bus;

/**
 * Implemented by domain event handlers (an `__invoke(SomeEvent $event): void` method).
 * Routed to the event bus by `config/services.yaml` (`_instanceof`).
 */
interface EventHandler
{
}
