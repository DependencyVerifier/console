<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Token;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * Revokes a token (no more enrollment with it), and optionally every agent enrolled with it (a leaked token).
 */
final readonly class RevokeEnrollmentToken implements AuditableCommand
{
    public function __construct(public string $tokenId, public bool $withAgents = false)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('fleet.revoke_enrollment_token', ['with_agents']);
    }

    public function auditTarget(): Target
    {
        return Target::of('enrollment_token', $this->tokenId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::for($this->auditAction(), ['with_agents' => $this->withAgents]);
    }
}
