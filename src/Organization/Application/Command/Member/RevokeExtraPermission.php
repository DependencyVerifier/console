<?php

declare(strict_types=1);

namespace Organization\Application\Command\Member;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Removes an extra permission.
 */
final readonly class RevokeExtraPermission implements AuditableCommand
{
    public function __construct(
        public string $teamId,
        public ?string $projectId,
        public string $userId,
        public string $permission,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('organization.revoke_extra_permission', ['user_id', 'permission']);
    }

    public function auditTarget(): Target
    {
        return null === $this->projectId ? Target::of('team', $this->teamId) : Target::of('project', $this->projectId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::for($this->auditAction(), ['user_id' => $this->userId, 'permission' => $this->permission]);
    }
}
