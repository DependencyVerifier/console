<?php

declare(strict_types=1);

namespace Instance\Application\Port;

use Instance\Domain\Model\PublishedRelease;

/**
 * The latest published release of the console. `null` means "no release known for now": an unreachable or invalid
 * upstream and the absence of a release are deliberately the same, eventually consistent within 12 hours. It never
 * throws a transport error.
 */
interface LatestConsoleReleaseSource
{
    public function latest(): ?PublishedRelease;
}
