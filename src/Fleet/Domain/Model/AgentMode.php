<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

/**
 * Collection modes accepted (docs/cadrage.md §6bis); `ecs` comes later.
 */
enum AgentMode: string
{
    case Host = 'host';
    case Container = 'container';
    /** In the application's own image: its container's file system only, one agent per replica. */
    case Embedded = 'embedded';
}
