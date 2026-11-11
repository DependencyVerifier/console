<?php

declare(strict_types=1);

namespace Shared\Application\Audit;

enum ActorType: string
{
    case User = 'user';
    case Cli = 'cli';
    case System = 'system';
    case Anonymous = 'anonymous';
    /** An enrolled agent, on the signed agent API (its id is the agent id, never a user id). */
    case Agent = 'agent';
}
