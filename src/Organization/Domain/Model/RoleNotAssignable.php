<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * A role can only grant a role lower than or equal to its own (decision 15).
 */
final class RoleNotAssignable extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('role_not_assignable');
    }
}
