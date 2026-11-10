<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * The email of a super admin is never changed by another account: with the new address and "forgotten password",
 * that would be a takeover of a peer. Revoking the role first is re-authenticated and the holder is told by email.
 */
final class SuperAdminEmailLocked extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('super_admin_email_locked');
    }
}
