<?php

declare(strict_types=1);

namespace Fleet\UI\Controller;

use Shared\UI\Routing\RouteCollectionInterface;

enum RouteCollection: string implements RouteCollectionInterface
{
    case AGENT_ENROLL = 'agent_enroll';
    case AGENT_POLL = 'agent_poll';

    public function prefixed(): string
    {
        return 'fleet_'.$this->value;
    }
}
