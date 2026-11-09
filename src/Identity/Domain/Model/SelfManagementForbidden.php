<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * An administrator never deactivates their own account (they would lock themselves out).
 */
final class SelfManagementForbidden extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('self_management_forbidden');
    }
}
