<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * A project with environments (or content declared by a context) cannot be deleted.
 */
final class ProjectNotEmpty extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('project_not_empty');
    }
}
