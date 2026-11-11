<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * Enrollment facts (host, mode, scope, version) out of the protocol bounds (protocol: invalid_payload).
 */
final class InvalidEnrollmentPayload extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('invalid_payload');
    }
}
