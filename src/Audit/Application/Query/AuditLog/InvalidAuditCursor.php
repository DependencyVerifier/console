<?php

declare(strict_types=1);

namespace Audit\Application\Query\AuditLog;

use Shared\Domain\BusinessRuleViolated;

final class InvalidAuditCursor extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('invalid_audit_cursor', 'Invalid pagination cursor.');
    }
}
