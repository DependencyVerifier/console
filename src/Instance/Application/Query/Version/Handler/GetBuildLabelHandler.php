<?php

declare(strict_types=1);

namespace Instance\Application\Query\Version\Handler;

use Instance\Application\Port\CurrentBuild;
use Instance\Application\Query\Version\GetBuildLabel;
use Instance\Domain\Model\BuildLabel;
use Shared\Application\Bus\QueryHandler;

final readonly class GetBuildLabelHandler implements QueryHandler
{
    public function __construct(private CurrentBuild $currentBuild)
    {
    }

    public function __invoke(GetBuildLabel $query): BuildLabel
    {
        return $this->currentBuild->label();
    }
}
