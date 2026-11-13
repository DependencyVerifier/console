<?php

declare(strict_types=1);

namespace Fleet\UI\Controller;

use Shared\UI\Routing\RouteCollectionInterface;

enum RouteCollection: string implements RouteCollectionInterface
{
    case AGENT_ENROLL = 'agent_enroll';
    case AGENT_POLL = 'agent_poll';
    case TEAM_HOSTS = 'team_hosts';
    case TEAM_AGENTS = 'team_agents';
    case TEAM_ENROLLMENT = 'team_enrollment';
    case TOKEN_CREATE = 'token_create';
    case TOKEN_APPROVE = 'token_approve';
    case TOKEN_REVOKE = 'token_revoke';
    case TOKEN_REVOKE_WITH_AGENTS = 'token_revoke_with_agents';
    case AGENT_APPROVE = 'agent_approve';
    case AGENT_REVOKE = 'agent_revoke';
    case AGENT = 'agent';
    case AGENT_SCAN = 'agent_scan';
    case AGENT_CONFIGURE = 'agent_configure';
    case HOST_DELETE = 'host_delete';
    case HOST_LABEL = 'host_label';
    case ADMIN_AGENTS = 'admin_agents';
    case INSTALL_SCRIPT = 'install_script';
    case AGENT_DOWNLOAD = 'agent_download';

    public function prefixed(): string
    {
        return 'fleet_'.$this->value;
    }
}
