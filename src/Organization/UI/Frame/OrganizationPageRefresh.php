<?php

declare(strict_types=1);

namespace Organization\UI\Frame;

use Shared\Application\Audit\AuditAction;
use Shared\UI\Action\PageRefreshMap;

/**
 * The usages behind the quotas (projects, environments per project, members with pending invitations) change: the
 * counters and the creation buttons they enable or disable are refreshed with the page.
 */
final class OrganizationPageRefresh implements PageRefreshMap
{
    private const array ACTIONS = [
        'organization.create_project', 'organization.delete_project',
        'organization.create_environment', 'organization.delete_environment',
        'organization.invite_member', 'organization.revoke_invitation',
        'organization.remove_team_member', 'organization.remove_project_member',
    ];

    public function refreshesPage(AuditAction $action): bool
    {
        return \in_array($action->name, self::ACTIONS, true);
    }
}
