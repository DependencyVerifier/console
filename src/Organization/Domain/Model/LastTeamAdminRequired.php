<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * Once established, a team keeps at least one active TEAM_ADMIN.
 */
final class LastTeamAdminRequired extends BusinessRuleViolated
{
    public function __construct()
    {
        parent::__construct('last_team_admin_required');
    }
}
