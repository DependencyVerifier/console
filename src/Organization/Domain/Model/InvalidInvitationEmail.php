<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

final class InvalidInvitationEmail extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('invalid_email_address');
    }
}
