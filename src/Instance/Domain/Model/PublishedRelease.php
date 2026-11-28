<?php

declare(strict_types=1);

namespace Instance\Domain\Model;

/**
 * The latest release of the console as published upstream (not to be confused with the GitHub Release itself: this
 * is what the anticorruption layer keeps of it).
 */
final readonly class PublishedRelease
{
    public function __construct(public ConsoleVersion $version, public ReleaseNotesUrl $notesUrl)
    {
    }
}
