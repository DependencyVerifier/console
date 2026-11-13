<?php

declare(strict_types=1);

namespace Fleet\Application\Query\Admin;

use Fleet\Application\Query\View\ConsoleKeyRotation;
use Shared\Application\Bus\Query;

/**
 * Where a console key rotation stands: how many approved agents pin the next key.
 *
 * @implements Query<ConsoleKeyRotation>
 */
final readonly class GetConsoleKeyRotation implements Query
{
}
