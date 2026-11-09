<?php

declare(strict_types=1);

namespace Organization\UI\Controller;

use Shared\UI\Routing\RouteCollectionInterface;

enum RouteCollection: string implements RouteCollectionInterface
{
    case TEAM = 'team';
    case TEAM_QUOTAS_FRAME = 'team_quotas_frame';
    case TEAM_RENAME = 'team_rename';
    case TEAM_INVITE = 'team_invite';
    case TEAM_MEMBER_ROLE = 'team_member_role';
    case TEAM_MEMBER_REMOVE = 'team_member_remove';
    case TEAM_MEMBER_EXTRA = 'team_member_extra';
    case INVITATION_RESEND = 'invitation_resend';
    case INVITATION_REVOKE = 'invitation_revoke';
    case PROJECT_CREATE = 'project_create';
    case PROJECT = 'project';
    case PROJECT_RENAME = 'project_rename';
    case PROJECT_DELETE = 'project_delete';
    case PROJECT_INVITE = 'project_invite';
    case PROJECT_MEMBER_ROLE = 'project_member_role';
    case PROJECT_MEMBER_REMOVE = 'project_member_remove';
    case ENVIRONMENT_CREATE = 'environment_create';
    case ENVIRONMENT_UPDATE = 'environment_update';
    case ENVIRONMENT_DELETE = 'environment_delete';
    case ADMIN_TEAMS = 'admin_teams';
    case ADMIN_TEAM_CREATE = 'admin_team_create';
    case ADMIN_TEAM_EDIT = 'admin_team_edit';
    case ADMIN_DEFAULT_QUOTAS = 'admin_default_quotas';

    public function prefixed(): string
    {
        return 'organization_'.$this->value;
    }
}
