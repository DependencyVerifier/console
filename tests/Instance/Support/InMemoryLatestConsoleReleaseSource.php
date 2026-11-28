<?php

declare(strict_types=1);

namespace Tests\Instance\Support;

use Instance\Application\Port\LatestConsoleReleaseSource;
use Instance\Domain\Model\ConsoleVersion;
use Instance\Domain\Model\PublishedRelease;
use Instance\Domain\Model\ReleaseNotesUrl;

/**
 * A fixed latest release (or none), counting how many times upstream was asked.
 */
final class InMemoryLatestConsoleReleaseSource implements LatestConsoleReleaseSource
{
    public int $calls = 0;

    public function __construct(private readonly ?PublishedRelease $release)
    {
    }

    public static function publishing(string $version): self
    {
        return new self(new PublishedRelease(
            ConsoleVersion::tryFrom($version) ?? throw new \LogicException($version),
            ReleaseNotesUrl::fromString('https://github.com/DependencyVerifier/console/releases/tag/v'.$version),
        ));
    }

    public static function none(): self
    {
        return new self(null);
    }

    public function latest(): ?PublishedRelease
    {
        ++$this->calls;

        return $this->release;
    }
}
