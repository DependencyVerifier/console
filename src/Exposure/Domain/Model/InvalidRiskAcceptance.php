<?php

declare(strict_types=1);

namespace Exposure\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * A risk is accepted with a justification and an expiry date, after today and within a year (cadrage §8).
 */
final class InvalidRiskAcceptance extends BusinessRuleViolated
{
    public function __construct(string $detail = '')
    {
        parent::__construct('invalid_risk_acceptance', $detail);
    }
}
