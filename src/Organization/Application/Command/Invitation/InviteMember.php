<?php

declare(strict_types=1);

namespace Organization\Application\Command\Invitation;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Invites an email to a team role, or to a project role (TEAM_GUEST is added at acceptance). One pending invitation per email and target; pending invitations count in maxMembers.
 */
final readonly class InviteMember implements AuditableCommand
{
    public function __construct(
        public string $invitationId,
        public string $teamId,
        public ?string $projectId,
        public string $email,
        public string $role,
    ) {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('organization.invite_member', ['role', 'invitation_id']);
    }

    public function auditTarget(): Target
    {
        return null === $this->projectId ? Target::of('team', $this->teamId) : Target::of('project', $this->projectId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::for($this->auditAction(), ['role' => $this->role, 'invitation_id' => $this->invitationId]);
    }
}
