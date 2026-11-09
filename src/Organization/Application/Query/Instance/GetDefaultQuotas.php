<?php

declare(strict_types=1);

namespace Organization\Application\Query\Instance;

use Shared\Application\Bus\Query;

/**
 * @implements Query<array<string, ?int>>
 */
final readonly class GetDefaultQuotas implements Query
{
}
