<?php

declare(strict_types=1);

namespace Identity\UI\Frame;

use Shared\Application\Audit\AuditAction;
use Shared\UI\Frame\ActionFrameMap;

/**
 * Identity's frames to refresh after an action.
 */
final class IdentityActionFrames implements ActionFrameMap
{
    private const array ADMIN_USER_ACTIONS = [
        'identity.reset_second_factor',
        'identity.deactivate_user',
        'identity.reactivate_user',
        'identity.grant_super_admin',
        'identity.revoke_super_admin',
    ];

    public function framesFor(AuditAction $action): array
    {
        return \in_array($action->name, self::ADMIN_USER_ACTIONS, true) ? [TurboFrame::AdminUsers] : [];
    }
}
