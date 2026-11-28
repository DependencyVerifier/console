<?php

declare(strict_types=1);

namespace Instance\Domain\Model;

use function Symfony\Component\String\u;

/**
 * Where an administrator reads the notes of a release: an absolute https URL, without credentials.
 */
final readonly class ReleaseNotesUrl
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $url): self
    {
        if ([] === u($url)->match('#^https://[^/@\s?\#]+/\S*$#D')) {
            throw new InvalidReleaseNotesUrl(\sprintf('Cannot use "%s" as release notes URL: an absolute https URL is required.', $url));
        }

        return new self($url);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
