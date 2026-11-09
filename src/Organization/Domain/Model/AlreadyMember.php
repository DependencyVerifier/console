<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * The account is already a member of this team or project.
 */
final class AlreadyMember extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('already_member');
    }
}
