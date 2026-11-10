<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * A role goes only to an account that may sign in: never a deleted, deactivated or notification-only one.
 */
final class AccountNotAssignable extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('account_not_assignable');
    }
}
