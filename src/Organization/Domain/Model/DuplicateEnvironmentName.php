<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * Environment names are unique per project, case-insensitive.
 */
final class DuplicateEnvironmentName extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('duplicate_environment_name');
    }
}
