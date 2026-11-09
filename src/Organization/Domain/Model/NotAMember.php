<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * The account is not a member of this team or project.
 */
final class NotAMember extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('not_a_member');
    }
}
