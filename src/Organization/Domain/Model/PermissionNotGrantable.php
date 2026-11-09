<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * An extra permission must be ○ for the role and held by the granting actor.
 */
final class PermissionNotGrantable extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('permission_not_grantable');
    }
}
