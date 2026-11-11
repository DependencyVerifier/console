<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Organization;

final readonly class TeamEnvironment
{
    public function __construct(public string $environmentId, public string $name, public string $projectId, public string $projectName)
    {
    }
}
