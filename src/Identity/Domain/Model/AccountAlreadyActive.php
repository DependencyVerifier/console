<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * An account already exists for the invited email: sign in to accept the invitation instead.
 */
final class AccountAlreadyActive extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('account_already_active');
    }
}
