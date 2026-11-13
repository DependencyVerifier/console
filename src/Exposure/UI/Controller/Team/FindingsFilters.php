<?php

declare(strict_types=1);

namespace Exposure\UI\Controller\Team;

use Exposure\Application\Query\ExposureScope;
use Exposure\Application\Query\FindingCriteria;
use Exposure\Application\Query\ListFindingHosts;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Organization\GetVisibleProjects;
use Shared\Application\Contract\Organization\ListTeamEnvironments;
use Shared\Application\Contract\Organization\TeamEnvironment;
use Symfony\Component\HttpFoundation\Request;

/**
 * The filters of a list of findings, read from the query string of the page (a GET form: the DataTable posts to the
 * same URL), and the choices of its selects.
 */
final readonly class FindingsFilters
{
    public function __construct(private QueryBus $queries)
    {
    }

    public function criteria(Request $request): FindingCriteria
    {
        $value = static fn (string $name): ?string => \is_string($request->query->get($name)) && '' !== $request->query->get($name) ? (string) $request->query->get($name) : null;

        $production = match ($value('production')) {
            '1' => true,
            '0' => false,
            default => null,
        };

        return new FindingCriteria($value('kind'), $value('severity'), $value('status'), $value('project'), $value('environment'), $value('host'), $production);
    }

    /**
     * The projects of the team the actor sees, by id (those with an environment: only they hold applications).
     *
     * @return array<string, string> project id => name
     */
    public function projects(string $teamId): array
    {
        $visible = $this->queries->ask(new GetVisibleProjects($teamId));
        $projects = [];
        foreach ($this->queries->ask(new ListTeamEnvironments($teamId)) as $environment) {
            if ($visible->sees($environment->projectId)) {
                $projects[$environment->projectId] = $environment->projectName;
            }
        }

        asort($projects);

        return $projects;
    }

    /**
     * The hosts of the findings of a perimeter the actor sees, by name.
     *
     * @return array<string, string> host id => name
     */
    public function hosts(string $teamId, ExposureScope $scope = ExposureScope::Team, ?string $id = null): array
    {
        return $this->queries->ask(new ListFindingHosts($teamId, $scope, $id));
    }

    /**
     * @return list<TeamEnvironment> the environments the actor sees of the team
     */
    public function teamEnvironments(string $teamId): array
    {
        $visible = $this->queries->ask(new GetVisibleProjects($teamId));

        return array_values(array_filter($this->queries->ask(new ListTeamEnvironments($teamId)), static fn (TeamEnvironment $e): bool => $visible->sees($e->projectId)));
    }

    /**
     * @return list<TeamEnvironment> the environments of a project
     */
    public function environments(string $teamId, string $projectId): array
    {
        return array_values(array_filter($this->queries->ask(new ListTeamEnvironments($teamId)), static fn (TeamEnvironment $e): bool => $e->projectId === $projectId));
    }
}
