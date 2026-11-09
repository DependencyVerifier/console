<?php

declare(strict_types=1);

namespace Organization\UI\Frame;

use Shared\Application\Audit\AuditAction;
use Shared\UI\Frame\ActionFrameMap;

/**
 * Actions that change a usage refresh the quota gauges (the lists are DataTables, reloaded after any stream).
 */
final class OrganizationActionFrames implements ActionFrameMap
{
    private const array QUOTA_ACTIONS = [
        'organization.invite_member', 'organization.revoke_invitation', 'organization.create_project',
        'organization.delete_project', 'organization.remove_team_member', 'organization.update_team',
    ];

    public function framesFor(AuditAction $action): array
    {
        return \in_array($action->name, self::QUOTA_ACTIONS, true) ? [TurboFrame::TeamQuotas] : [];
    }
}
