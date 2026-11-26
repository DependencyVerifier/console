<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * Runtime facts of an agent (mode, scope, Compose project, version) out of the protocol bounds (protocol:
 * invalid_payload). The host facts have their own refusal, InvalidHostFacts, with the same rule key.
 */
final class InvalidEnrollmentPayload extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('invalid_payload');
    }
}
