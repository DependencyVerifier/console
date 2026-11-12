<?php

declare(strict_types=1);

namespace Exposure\Domain\Model;

enum FindingKind: string
{
    case Vulnerability = 'vulnerability';
    case EndOfLife = 'eol';
    case Outdated = 'outdated';
}
