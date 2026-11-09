<?php

declare(strict_types=1);

namespace Shared\Domain\Authorization;

/**
 * Published permission catalogue (docs/cadrage.md §7bis). Voters test permissions, never roles. Each new context
 * extends this catalogue on purpose: it is the language shared by the resolver, the UI and the tests.
 */
enum Permission: string
{
    case AccountLogin = 'account.login';

    case InstanceSettings = 'instance.settings';
    case InstanceUsersManage = 'instance.users.manage';
    case InstanceAuditView = 'instance.audit.view';
    case InstanceSuperAdminManage = 'instance.super_admin.manage';
    case InstanceAgentsView = 'instance.agents.view';
    case InstanceDefaultQuotasManage = 'instance.default_quotas.manage';
    case TeamCreate = 'team.create';
    case TeamDelete = 'team.delete';
    case TeamQuotasManage = 'team.quotas.manage';

    case TeamEdit = 'team.edit';
    case TeamMembersManage = 'team.members.manage';
    case TeamAuditView = 'team.audit.view';
    case TeamChannelsManage = 'team.channels.manage';
    case HostEnroll = 'host.enroll';
    case HostApprove = 'host.approve';
    case HostRevoke = 'host.revoke';
    case HostDelete = 'host.delete';
    case HostOrder = 'host.order';
    case ProjectCreate = 'project.create';

    case HostView = 'host.view';
    case ProjectEdit = 'project.edit';
    case ProjectDelete = 'project.delete';
    case ProjectMembersManage = 'project.members.manage';
    case ProjectEnvironmentsManage = 'project.environments.manage';
    case ProjectAppsAssign = 'project.apps.assign';
    case ProjectScanNow = 'project.scan_now';
    case ProjectNotificationsManage = 'project.notifications.manage';
    case ProjectNotificationsSubscribe = 'project.notifications.subscribe';
    case FindingView = 'finding.view';
    case FindingAcceptRisk = 'finding.accept_risk';
    case FindingExport = 'finding.export';

    public function scope(): PermissionScope
    {
        return match ($this) {
            self::AccountLogin,
            self::InstanceSettings,
            self::InstanceUsersManage,
            self::InstanceAuditView,
            self::InstanceSuperAdminManage,
            self::InstanceAgentsView,
            self::InstanceDefaultQuotasManage,
            self::TeamCreate,
            self::TeamDelete,
            self::TeamQuotasManage => PermissionScope::Instance,
            self::TeamEdit,
            self::TeamMembersManage,
            self::TeamAuditView,
            self::TeamChannelsManage,
            self::HostEnroll,
            self::HostApprove,
            self::HostRevoke,
            self::HostDelete,
            self::HostOrder,
            self::ProjectCreate => PermissionScope::Team,
            default => PermissionScope::Project,
        };
    }
}
