<?php

declare(strict_types=1);

namespace Exposure\UI\Controller\Team;

use Exposure\Application\Query\FindingCriteria;
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

        return new FindingCriteria($value('kind'), $value('severity'), $value('status'), $value('project'), $value('environment'));
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
     * @return list<TeamEnvironment> the environments of a project
     */
    public function environments(string $teamId, string $projectId): array
    {
        return array_values(array_filter($this->queries->ask(new ListTeamEnvironments($teamId)), static fn (TeamEnvironment $e): bool => $e->projectId === $projectId));
    }
}
