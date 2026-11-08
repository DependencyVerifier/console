<?php

declare(strict_types=1);

namespace Shared\Application\Bus;

/**
 * Marker for an intention to change state. Handled exactly once, inside a database transaction.
 */
interface Command
{
}
