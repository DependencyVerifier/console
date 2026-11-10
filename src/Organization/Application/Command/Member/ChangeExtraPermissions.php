<?php

declare(strict_types=1);

namespace Organization\Application\Command\Member;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

use function Symfony\Component\String\u;

/**
 * The "Permissions" dialog: the full set of ○ permissions the member should hold on top of their role, on the team
 * (projectId null) or on a project. Every difference is granted or revoked in one transaction, all or nothing; each
 * one is recorded in the grant history.
 */
final readonly class ChangeExtraPermissions implements AuditableCommand
{
    /**
     * @param list<string> $permissions
     */
    public function __construct(
        public string $teamId,
        public ?string $projectId,
        public string $userId,
        public array $permissions,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('organization.change_extra_permissions', ['user_id', 'permissions']);
    }

    public function auditTarget(): Target
    {
        return null === $this->projectId ? Target::of('team', $this->teamId) : Target::of('project', $this->projectId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::for($this->auditAction(), ['user_id' => $this->userId, 'permissions' => u(',')->join($this->permissions)->toString()]);
    }
}
