<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * One pending invitation per email and target.
 */
final class InvitationAlreadyPending extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('invitation_already_pending');
    }
}
