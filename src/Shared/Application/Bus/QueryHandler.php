<?php

declare(strict_types=1);

namespace Shared\Application\Bus;

/**
 * Implemented by query handlers (an `__invoke(SomeQuery $query): Result` method).
 * Routed to the query bus by `config/services.yaml` (`_instanceof`).
 */
interface QueryHandler
{
}
