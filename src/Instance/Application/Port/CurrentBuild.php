<?php

declare(strict_types=1);

namespace Instance\Application\Port;

use Instance\Domain\Model\BuildLabel;

/**
 * The build the console runs, as written into its image.
 */
interface CurrentBuild
{
    public function label(): BuildLabel;
}
