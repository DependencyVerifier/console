<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

/**
 * `AGENT_SCOPE` of a container agent (§6bis): its Compose project, the labeled containers of it, or the whole host.
 */
enum AgentScope: string
{
    case Compose = 'compose';
    case Labeled = 'labeled';
    case Host = 'host';

    public function needsComposeProject(): bool
    {
        return self::Host !== $this;
    }
}
