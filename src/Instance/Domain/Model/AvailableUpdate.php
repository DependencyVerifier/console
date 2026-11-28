<?php

declare(strict_types=1);

namespace Instance\Domain\Model;

/**
 * A published release strictly newer than the running console: there is none for an equal or older release.
 */
final readonly class AvailableUpdate
{
    private function __construct(private ConsoleVersion $current, private PublishedRelease $release)
    {
    }

    public static function between(ConsoleVersion $current, PublishedRelease $release): ?self
    {
        return $release->version->isNewerThan($current) ? new self($current, $release) : null;
    }

    public function currentVersion(): string
    {
        return $this->current->toString();
    }

    public function latestVersion(): string
    {
        return $this->release->version->toString();
    }

    public function notesUrl(): string
    {
        return $this->release->notesUrl->toString();
    }
}
