<?php

declare(strict_types=1);

namespace Inventory\Application\Security;

use Shared\Application\Contract\Organization\TeamEnvironment;

/**
 * What of a team's inventory the actor sees: everything for a member, the applications of its projects (and the hosts
 * and images they use) for a TEAM_GUEST.
 */
final readonly class InventoryScope
{
    /**
     * @param array<string, TeamEnvironment> $environments        every environment of the team, by id
     * @param list<string>|null              $visibleEnvironments null: every one
     * @param list<string>|null              $visibleProjects     null: every one
     */
    public function __construct(public string $teamId, public array $environments, public ?array $visibleEnvironments, public ?array $visibleProjects = null)
    {
    }

    public function seesProject(string $projectId): bool
    {
        return null === $this->visibleProjects || \in_array($projectId, $this->visibleProjects, true);
    }

    public function seesAll(): bool
    {
        return null === $this->visibleEnvironments;
    }

    public function seesEnvironment(?string $environmentId): bool
    {
        return $this->seesAll() || (null !== $environmentId && \in_array($environmentId, $this->visibleEnvironments ?? [], true));
    }

    /**
     * `Project / environment`, or '' when unknown.
     */
    public function environmentName(?string $environmentId): string
    {
        $environment = $this->environments[$environmentId ?? ''] ?? null;

        return null === $environment ? '' : $environment->projectName.' / '.$environment->name;
    }

    /**
     * @return list<string> the environments of a project
     */
    public function environmentsOf(string $projectId): array
    {
        return array_values(array_map(static fn (TeamEnvironment $e): string => $e->environmentId, array_filter($this->environments, static fn (TeamEnvironment $e): bool => $e->projectId === $projectId)));
    }
}
