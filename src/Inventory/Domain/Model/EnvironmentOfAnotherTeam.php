<?php

declare(strict_types=1);

namespace Inventory\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * An application is attached only to an environment of the team of its host.
 */
final class EnvironmentOfAnotherTeam extends BusinessRuleViolated
{
    public function __construct(string $detail = '')
    {
        parent::__construct('environment_of_another_team', $detail);
    }
}
