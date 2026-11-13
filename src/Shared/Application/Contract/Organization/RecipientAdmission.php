<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Organization;

/**
 * Published port of Organization, called inside the caller's command (same transaction, no nested bus): a
 * notification-only account attached to a project (decision 16, §7bis). Its membership bounds what it may receive:
 * PROJECT_VIEWER of the project, and TEAM_GUEST of the team when not a member yet. Organization locks the team and
 * checks `project.notifications.manage` itself (404 out of scope, 403 without the permission). Idempotent.
 */
interface RecipientAdmission
{
    public function admitRecipient(string $teamId, string $projectId, string $userId): void;
}
