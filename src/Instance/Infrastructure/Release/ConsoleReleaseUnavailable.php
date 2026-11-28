<?php

declare(strict_types=1);

namespace Instance\Infrastructure\Release;

/**
 * Upstream could not tell the latest release (transport, status, malformed or unexpected answer). Never leaves the
 * anticorruption layer: CachedConsoleReleaseSource turns it into "no release known".
 */
final class ConsoleReleaseUnavailable extends \RuntimeException
{
}
