<?php

declare(strict_types=1);

namespace Shared\Application\Audit;

enum OutcomeKind: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Denied = 'denied';
}
