<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Organization;

/**
 * Published port of Organization, called inside the caller's command (same transaction, no nested bus) when a
 * notification recipient confirms its address (no session: the link is the authorization): its notification-only
 * account is attached to the project on behalf of the manager who added it (decision 16, §7bis). Its membership bounds
 * what it may receive: PROJECT_VIEWER of the project, and TEAM_GUEST of the team when not a member yet. Organization
 * locks the team and checks that the manager still holds `project.notifications.manage` there (403 otherwise; 404
 * for a team or a project gone). Idempotent.
 */
interface RecipientAdmission
{
    public function admitRecipient(string $teamId, string $projectId, string $userId, string $grantedBy): void;
}
