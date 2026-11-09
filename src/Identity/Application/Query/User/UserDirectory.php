<?php

declare(strict_types=1);

namespace Identity\Application\Query\User;

use Shared\Application\Contract\Identity\AccountView;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

/**
 * Read side of the accounts (DBAL), for the instance administration.
 */
interface UserDirectory
{
    /**
     * @return Page<UserSummary>
     */
    public function page(PageRequest $page): Page;

    /**
     * @param list<string> $userIds
     *
     * @return list<AccountView>
     */
    public function byIds(array $userIds): array;
}
