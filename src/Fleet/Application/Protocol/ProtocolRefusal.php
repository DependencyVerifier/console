<?php

declare(strict_types=1);

namespace Fleet\Application\Protocol;

use Shared\Domain\BusinessRuleViolated;

/**
 * A request refused by the protocol rules (signature, clock, digest, nonce, payload…): audited as
 * `rule:<problem code>`, answered as a problem document.
 */
final class ProtocolRefusal extends BusinessRuleViolated
{
    public function __construct(public readonly Problem $problem, public readonly string $detail = '')
    {
        parent::__construct($problem->value);
    }
}
