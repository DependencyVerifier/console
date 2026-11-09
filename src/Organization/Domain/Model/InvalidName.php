<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

final class InvalidName extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('invalid_name');
    }
}
