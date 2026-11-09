<?php

declare(strict_types=1);

namespace Identity\Application\Query\User\Handler;

use Identity\Application\Query\User\ListUsers;
use Identity\Application\Query\User\UserDirectory;
use Identity\Application\Query\User\UserSummary;
use Shared\Application\Authorization\PermissionChecker;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Query\Page;
use Shared\Domain\Authorization\Permission;

final readonly class ListUsersHandler implements QueryHandler
{
    public function __construct(private UserDirectory $directory, private PermissionChecker $permissions)
    {
    }

    /**
     * @return Page<UserSummary>
     */
    public function __invoke(ListUsers $query): Page
    {
        if (!$this->permissions->isGranted(Permission::InstanceUsersManage)) {
            throw PermissionDenied::for(Permission::InstanceUsersManage->value);
        }

        return $this->directory->page($query->page, $query->includeDeleted);
    }
}
