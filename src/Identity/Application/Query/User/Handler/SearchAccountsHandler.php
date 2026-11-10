<?php

declare(strict_types=1);

namespace Identity\Application\Query\User\Handler;

use Identity\Application\Query\User\UserDirectory;
use Shared\Application\Authorization\PermissionChecker;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Identity\AccountMatch;
use Shared\Application\Contract\Identity\SearchAccounts;
use Shared\Domain\Authorization\Permission;

use function Symfony\Component\String\u;

final readonly class SearchAccountsHandler implements QueryHandler
{
    public function __construct(private UserDirectory $directory, private PermissionChecker $permissions)
    {
    }

    /**
     * @return list<AccountMatch>
     */
    public function __invoke(SearchAccounts $query): array
    {
        if (!$this->permissions->isGranted(Permission::InstanceUsersManage)) {
            throw PermissionDenied::for(Permission::InstanceUsersManage->value);
        }

        $term = u($query->term)->trim();
        if ($term->length() < SearchAccounts::MIN_LENGTH) {
            return [];
        }

        return $this->directory->search($term->toString(), SearchAccounts::MAX_RESULTS);
    }
}
