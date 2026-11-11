<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Organization;

final readonly class VisibleProjects
{
    /**
     * @param list<string> $projectIds the projects seen when not all of them
     */
    public function __construct(public bool $all, public array $projectIds = [])
    {
    }

    public function sees(string $projectId): bool
    {
        return $this->all || \in_array($projectId, $this->projectIds, true);
    }
}
