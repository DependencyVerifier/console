<?php

declare(strict_types=1);

namespace Instance\Infrastructure\Release;

use Instance\Domain\Model\ConsoleVersion;
use Instance\Domain\Model\PublishedRelease;
use Instance\Domain\Model\ReleaseNotesUrl;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The single dictionary of the anticorruption layer: a GitHub tag becomes a version, and the notes URL is built from
 * that validated version (the `html_url` of the answer is never read).
 */
final readonly class ConsoleReleaseTranslator
{
    public function __construct(
        #[Autowire(param: 'console_release_notes_base_url')]
        private string $notesBaseUrl,
    ) {
    }

    /**
     * @throws ConsoleReleaseUnavailable when the tag is not a strict `vX.Y.Z`
     */
    public function fromTag(string $tag): PublishedRelease
    {
        $version = ConsoleVersion::tryFrom($tag) ?? throw new ConsoleReleaseUnavailable('There is no semantic version in the latest release tag.');

        return new PublishedRelease($version, ReleaseNotesUrl::fromString($this->notesBaseUrl.'v'.$version->toString()));
    }
}
