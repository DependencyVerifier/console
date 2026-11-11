<?php

declare(strict_types=1);

namespace Fleet\UI\Frame;

use Shared\Application\Audit\AuditAction;
use Shared\UI\Action\PageRefreshMap;

/**
 * Fleet actions change counters and quota-bound buttons (a freed agent seat enables "New token" again): the page is
 * refreshed, the message goes through the flash bag.
 */
final class FleetPageRefresh implements PageRefreshMap
{
    private const array ACTIONS = [
        'fleet.approve_agent', 'fleet.revoke_agent', 'fleet.delete_host',
        'fleet.approve_enrollment_token', 'fleet.revoke_enrollment_token',
    ];

    public function refreshesPage(AuditAction $action): bool
    {
        return \in_array($action->name, self::ACTIONS, true);
    }
}
