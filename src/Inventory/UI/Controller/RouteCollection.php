<?php

declare(strict_types=1);

namespace Inventory\UI\Controller;

use Shared\UI\Routing\RouteCollectionInterface;

enum RouteCollection: string implements RouteCollectionInterface
{
    case AGENT_MANIFEST = 'agent_manifest';
    case AGENT_SNAPSHOT = 'agent_snapshot';
    case AGENT_COMPLETE = 'agent_complete';

    public function prefixed(): string
    {
        return 'inventory_'.$this->value;
    }
}
