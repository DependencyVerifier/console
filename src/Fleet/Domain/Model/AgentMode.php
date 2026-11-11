<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

/**
 * Collection modes accepted in this step (docs/cadrage.md §6bis); `embedded` and `ecs` come later.
 */
enum AgentMode: string
{
    case Host = 'host';
    case Container = 'container';
}
