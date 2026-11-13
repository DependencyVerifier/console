<?php

declare(strict_types=1);

namespace Inventory\UI\Controller;

use Shared\UI\Routing\RouteCollectionInterface;

enum RouteCollection: string implements RouteCollectionInterface
{
    case AGENT_MANIFEST = 'agent_manifest';
    case AGENT_SNAPSHOT = 'agent_snapshot';
    case AGENT_COMPLETE = 'agent_complete';
    case TEAM_APPLICATIONS = 'team_applications';
    case PROJECT_APPLICATIONS = 'project_applications';
    case APPLICATION = 'application';
    case APPLICATION_ATTACH = 'application_attach';
    case APPLICATION_LINKS = 'application_links';
    case APPLICATION_DETACH = 'application_detach';
    case HOST = 'host';
    case IMAGE = 'image';
    case TEAM_INVENTORY_FRAME = 'team_inventory_frame';

    public function prefixed(): string
    {
        return 'inventory_'.$this->value;
    }
}
