<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * The invitation was accepted, revoked or has expired.
 */
final class InvitationNotPending extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('invitation_not_pending');
    }
}
