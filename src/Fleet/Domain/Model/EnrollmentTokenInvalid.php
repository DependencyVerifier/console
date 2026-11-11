<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * Unknown, used up or revoked enrollment token (protocol: token_invalid).
 */
final class EnrollmentTokenInvalid extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('token_invalid');
    }
}
