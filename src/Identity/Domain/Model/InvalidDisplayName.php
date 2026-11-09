<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

final class InvalidDisplayName extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('invalid_display_name');
    }
}
