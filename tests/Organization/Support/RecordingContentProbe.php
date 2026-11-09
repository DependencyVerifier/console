<?php

declare(strict_types=1);

namespace Tests\Organization\Support;

use Shared\Application\Content\ProjectContentProbe;

/**
 * A content probe of a future context: answers fixed values and records the ids it was asked about.
 */
final class RecordingContentProbe implements ProjectContentProbe
{
    /** @var list<string> */
    public array $asked = [];

    public function __construct(private readonly bool $project = false, private readonly bool $environment = false)
    {
    }

    public function projectHasContent(string $projectId): bool
    {
        $this->asked[] = $projectId;

        return $this->project;
    }

    public function environmentHasContent(string $environmentId): bool
    {
        $this->asked[] = $environmentId;

        return $this->environment;
    }
}
