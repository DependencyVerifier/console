<?php

declare(strict_types=1);

namespace Shared\Application\Audit;

enum ActorType: string
{
    case User = 'user';
    case Cli = 'cli';
    case System = 'system';
    case Anonymous = 'anonymous';
}
