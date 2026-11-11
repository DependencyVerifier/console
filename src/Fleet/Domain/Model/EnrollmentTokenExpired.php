<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * The enrollment token expired (protocol: token_expired).
 */
final class EnrollmentTokenExpired extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('token_expired');
    }
}
