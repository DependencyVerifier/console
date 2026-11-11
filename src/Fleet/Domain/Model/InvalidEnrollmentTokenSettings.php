<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * A reusable token needs an expiry within 90 days and between 1 and 10 000 uses.
 */
final class InvalidEnrollmentTokenSettings extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('invalid_enrollment_token_settings');
    }
}
