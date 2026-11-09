<?php

declare(strict_types=1);

namespace Identity\Application\Query\User\Handler;

use Identity\Application\Query\User\UserDirectory;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Identity\AccountView;
use Shared\Application\Contract\Identity\FindAccounts;

/**
 * Identity's published query: emails of accounts by id. The caller already decided which ids the actor may see.
 */
final readonly class FindAccountsHandler implements QueryHandler
{
    public function __construct(private UserDirectory $directory)
    {
    }

    /**
     * @return array<string, AccountView>
     */
    public function __invoke(FindAccounts $query): array
    {
        $accounts = [];
        foreach ($this->directory->byIds($query->userIds) as $account) {
            $accounts[$account->userId] = $account;
        }

        return $accounts;
    }
}
