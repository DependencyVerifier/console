<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * Only a pending reusable token can be approved.
 */
final class EnrollmentTokenNotApprovable extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('enrollment_token_not_approvable');
    }
}
