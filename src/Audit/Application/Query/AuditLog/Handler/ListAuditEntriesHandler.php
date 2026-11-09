<?php

declare(strict_types=1);

namespace Audit\Application\Query\AuditLog\Handler;

use Audit\Application\Query\AuditLog\AuditCursor;
use Audit\Application\Query\AuditLog\AuditLogFilter;
use Audit\Application\Query\AuditLog\AuditLogReader;
use Audit\Application\Query\AuditLog\AuditPage;
use Audit\Application\Query\AuditLog\ListAuditEntries;
use Shared\Application\Authorization\PermissionChecker;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Bus\QueryHandler;
use Shared\Domain\Authorization\Permission;

/**
 * Single judge of the permission: refusals are audited once by the query bus.
 */
final readonly class ListAuditEntriesHandler implements QueryHandler
{
    public function __construct(private PermissionChecker $permissions, private AuditLogReader $reader)
    {
    }

    public function __invoke(ListAuditEntries $query): AuditPage
    {
        $permission = null === $query->teamId ? Permission::InstanceAuditView : Permission::TeamAuditView;
        if (!$this->permissions->isGranted($permission, $query->teamId)) {
            throw PermissionDenied::for($permission->value);
        }

        return $this->reader->page(
            new AuditLogFilter($query->teamId, $query->outcome),
            null === $query->cursor ? null : AuditCursor::decode($query->cursor),
            ListAuditEntries::PAGE_SIZE,
        );
    }
}
