<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * The invitation was sent to another email address.
 */
final class InvitationEmailMismatch extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('invitation_email_mismatch');
    }
}
