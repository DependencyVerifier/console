<?php

declare(strict_types=1);

namespace Identity\Application\Query\User;

use Shared\Application\Bus\Query;

/**
 * @implements Query<list<UserSummary>>
 */
final readonly class ListUsers implements Query
{
}
