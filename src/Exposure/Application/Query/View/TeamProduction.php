<?php

declare(strict_types=1);

namespace Exposure\Application\Query\View;

/**
 * One team on the home dashboard: its production, and the figures of each of its projects with a production
 * environment.
 */
final readonly class TeamProduction
{
    /**
     * @param list<array{projectId: string, name: string, knownExploited: int, critical: int, high: int, endsOfLife: int}> $projects
     */
    public function __construct(public string $teamId, public string $name, public string $role, public ProductionExposure $production, public array $projects)
    {
    }
}
