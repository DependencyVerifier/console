<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * An operation that the current second factor state does not allow (verifying a TOTP that is not active, an email
 * code once TOTP is on…). Distinct from a wrong code, which is a `Rejected` outcome.
 */
final class InvalidSecondFactorTransition extends BusinessRuleViolated
{
    public function __construct(string $operation, string $state)
    {
        parent::__construct('invalid_second_factor_transition', \sprintf('Cannot "%s" a second factor in state "%s".', $operation, $state));
    }
}
