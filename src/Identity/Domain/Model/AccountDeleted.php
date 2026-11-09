<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * A deleted account is restored (back as deactivated) before it can be reactivated.
 */
final class AccountDeleted extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('account_deleted');
    }
}
