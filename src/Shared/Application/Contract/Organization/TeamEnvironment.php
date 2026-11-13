<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Organization;

/**
 * An environment of a team, with its project, and whether it is a production one.
 */
final readonly class TeamEnvironment
{
    public function __construct(public string $environmentId, public string $name, public string $projectId, public string $projectName, public bool $production = false)
    {
    }
}
