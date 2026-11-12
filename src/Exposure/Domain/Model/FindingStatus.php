<?php

declare(strict_types=1);

namespace Exposure\Domain\Model;

enum FindingStatus: string
{
    case Open = 'open';
    case Fixed = 'fixed';
}
