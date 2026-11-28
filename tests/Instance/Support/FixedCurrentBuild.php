<?php

declare(strict_types=1);

namespace Tests\Instance\Support;

use Instance\Application\Port\CurrentBuild;
use Instance\Domain\Model\BuildLabel;

final readonly class FixedCurrentBuild implements CurrentBuild
{
    public function __construct(private string $appVersion)
    {
    }

    public function label(): BuildLabel
    {
        return BuildLabel::fromRaw($this->appVersion);
    }
}
