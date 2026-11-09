<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

/**
 * The 9 team quotas of docs/cadrage.md §5ter. This step enforces maxProjects, maxEnvironmentsPerProject and maxMembers;
 * the others are stored and editable, enforced by their contexts later.
 */
enum QuotaName: string
{
    case MaxProjects = 'max_projects';
    case MaxEnvironmentsPerProject = 'max_environments_per_project';
    case MaxAgents = 'max_agents';
    case MaxMembers = 'max_members';
    case MaxNotificationOnlyAccounts = 'max_notification_only_accounts';
    case MaxNotificationChannels = 'max_notification_channels';
    case MaxReusableEnrollmentTokens = 'max_reusable_enrollment_tokens';
    case SnapshotRetentionDays = 'snapshot_retention_days';
    case MinScanIntervalMinutes = 'min_scan_interval_minutes';

    public function defaultValue(): int
    {
        return match ($this) {
            self::MaxProjects => 10,
            self::MaxEnvironmentsPerProject => 3,
            self::MaxAgents, self::MaxMembers => 25,
            self::MaxNotificationOnlyAccounts => 50,
            self::MaxNotificationChannels => 5,
            self::MaxReusableEnrollmentTokens => 3,
            self::SnapshotRetentionDays => 90,
            self::MinScanIntervalMinutes => 60,
        };
    }
}
