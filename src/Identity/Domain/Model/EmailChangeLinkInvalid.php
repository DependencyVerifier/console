<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

final class EmailChangeLinkInvalid extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('email_change_link_invalid');
    }
}
