<?php

declare(strict_types=1);

namespace Identity\Application\Query\User;

use Shared\Application\Contract\Identity\AccountMatch;
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
    public function page(PageRequest $page, bool $includeDeleted): Page;

    /**
     * @param list<string> $userIds
     *
     * @return list<AccountView>
     */
    public function byIds(array $userIds): array;

    /**
     * Accounts that may be given a role (active, allowed to sign in), whose email or display name starts with the
     * term (case-insensitive), by email.
     *
     * @return list<AccountMatch>
     */
    public function search(string $term, int $limit): array;

    /**
     * Ids of the accounts whose email or display name contains the term (case-insensitive), at most $limit.
     *
     * @return list<string>
     */
    public function matchIds(string $term, int $limit): array;
}
