<?php

declare(strict_types=1);

namespace Identity\Application\Query\User;

use Shared\Application\Bus\Query;

/**
 * @implements Query<UserProfile>
 */
final readonly class GetUserProfile implements Query
{
    public function __construct(public string $userId)
    {
    }
}
