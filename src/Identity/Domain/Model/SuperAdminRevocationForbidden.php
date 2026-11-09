<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * The last active super admin cannot be revoked or deactivated, and nobody revokes or deactivates themselves.
 */
final class SuperAdminRevocationForbidden extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('super_admin_revocation_forbidden');
    }
}
