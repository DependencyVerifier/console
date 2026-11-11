<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Token;

use Shared\Application\Audit\AuditableCommand;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\Target;

/**
 * A pending reusable token is approved: its next enrollments give approved agents.
 */
final readonly class ApproveEnrollmentToken implements AuditableCommand
{
    public function __construct(public string $tokenId)
    {
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::named('fleet.approve_enrollment_token');
    }

    public function auditTarget(): Target
    {
        return Target::of('enrollment_token', $this->tokenId);
    }

    public function auditContext(): AuditContext
    {
        return AuditContext::empty();
    }
}
