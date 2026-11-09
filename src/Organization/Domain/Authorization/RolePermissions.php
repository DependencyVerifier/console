<?php

declare(strict_types=1);

namespace Organization\Domain\Authorization;

use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\TeamRole;
use Shared\Domain\Authorization\Permission as P;

/**
 * The single table of docs/cadrage.md §7bis: for each role, the permissions included by default (✓) and those that
 * may be granted on top (○). Used by the resolver, the grant UI and the tests. The super admin holds everything and
 * is not in this table; instance permissions are never granted through a role.
 */
final class RolePermissions
{
    /**
     * @return list<P>
     */
    public static function defaultsOfTeamRole(TeamRole $role): array
    {
        return match ($role) {
            TeamRole::Admin => [
                P::TeamEdit, P::TeamMembersManage, P::TeamAuditView, P::TeamChannelsManage,
                P::HostEnroll, P::HostApprove, P::HostRevoke, P::HostDelete, P::HostOrder, P::ProjectCreate,
                ...self::PROJECT_ADMIN_LIKE,
                P::ProjectDelete,
            ],
            TeamRole::Member => [
                P::HostView, P::ProjectEnvironmentsManage, P::ProjectAppsAssign, P::ProjectScanNow,
                P::ProjectNotificationsManage, P::ProjectNotificationsSubscribe, P::FindingView, P::FindingExport,
            ],
            TeamRole::Viewer => [P::HostView, P::ProjectNotificationsSubscribe, P::FindingView, P::FindingExport],
            TeamRole::Guest => [],
        };
    }

    /**
     * @return list<P>
     */
    public static function grantableToTeamRole(TeamRole $role): array
    {
        return match ($role) {
            TeamRole::Admin => [],
            TeamRole::Member => [
                P::TeamAuditView, P::TeamChannelsManage, P::HostEnroll, P::HostApprove, P::HostRevoke, P::HostDelete,
                P::HostOrder, P::ProjectCreate, P::ProjectEdit, P::ProjectDelete, P::ProjectMembersManage, P::FindingAcceptRisk,
            ],
            TeamRole::Viewer => [P::TeamAuditView],
            TeamRole::Guest => [P::ProjectCreate],
        };
    }

    /**
     * @return list<P>
     */
    public static function defaultsOfProjectRole(ProjectRole $role): array
    {
        return match ($role) {
            ProjectRole::Admin => self::PROJECT_ADMIN_LIKE,
            ProjectRole::Member => [P::HostView, P::ProjectScanNow, P::ProjectNotificationsSubscribe, P::FindingView, P::FindingExport],
            ProjectRole::Viewer => [P::HostView, P::ProjectNotificationsSubscribe, P::FindingView, P::FindingExport],
        };
    }

    /**
     * @return list<P>
     */
    public static function grantableToProjectRole(ProjectRole $role): array
    {
        return match ($role) {
            ProjectRole::Admin => [P::ProjectDelete],
            ProjectRole::Member => [
                P::ProjectEdit, P::ProjectMembersManage, P::ProjectEnvironmentsManage, P::ProjectAppsAssign,
                P::ProjectNotificationsManage, P::FindingAcceptRisk,
            ],
            ProjectRole::Viewer => [P::ProjectScanNow],
        };
    }

    private const array PROJECT_ADMIN_LIKE = [
        P::HostView, P::ProjectEdit, P::ProjectMembersManage, P::ProjectEnvironmentsManage, P::ProjectAppsAssign,
        P::ProjectScanNow, P::ProjectNotificationsManage, P::ProjectNotificationsSubscribe, P::FindingView,
        P::FindingAcceptRisk, P::FindingExport,
    ];
}
