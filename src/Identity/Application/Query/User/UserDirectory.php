<?php

declare(strict_types=1);

namespace Identity\Application\Query\User;

/**
 * Read side of the accounts (DBAL), for the instance administration.
 */
interface UserDirectory
{
    /**
     * @return list<UserSummary>
     */
    public function all(): array;
}
