<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * An environment holding content declared by a context cannot be deleted.
 */
final class EnvironmentNotEmpty extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('environment_not_empty');
    }
}
