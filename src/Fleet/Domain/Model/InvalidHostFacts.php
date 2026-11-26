<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * Host facts out of bounds: an empty hostname, a value too long, a control or format character, a malformed machine
 * id hash. Same rule key as the other enrollment facts (protocol: invalid_payload), so the agent gets the same answer;
 * the field it names is for the console's logs only, never its value.
 */
final class InvalidHostFacts extends BusinessRuleViolated
{
    public function __construct(public readonly string $field)
    {
        parent::__construct('invalid_payload');
    }
}
