<?php

declare(strict_types=1);

namespace Exposure\Application\Security;

use Exposure\Application\Query\ExposureFilter;
use Exposure\Application\Query\ExposureScope;
use Exposure\Domain\Model\SubjectType;
use Shared\Application\Authorization\PermissionChecker;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Inventory\GetApplicationPlacement;
use Shared\Application\Contract\Organization\GetVisibleProjects;
use Shared\Application\Contract\Organization\ListTeamEnvironments;
use Shared\Domain\Authorization\Permission;

/**
 * Who sees the findings of a subject, and who accepts their risk (cadrage §7bis): the findings of an application
 * attached to a project follow that project (`finding.view`, `finding.accept_risk`); those of a host or of an
 * application not attached yet belong to the fleet: seen by who sees every project of the team, decided with
 * `host.approve`. Out of sight is 404.
 */
final readonly class FindingAccess
{
    public function __construct(private QueryBus $queries, private PermissionChecker $permissions)
    {
    }

    /**
     * @throws ResourceNotFound
     *
     * @return ?string the project of the subject, null for the fleet
     */
    public function project(string $teamId, SubjectType $type, string $subjectId): ?string
    {
        $visible = $this->queries->ask(new GetVisibleProjects($teamId));
        $project = SubjectType::Application === $type ? $this->projectOf($teamId, $subjectId) : null;
        if (!$visible->all && (null === $project || !$visible->sees($project))) {
            throw ResourceNotFound::of('finding');
        }

        return $project;
    }

    /**
     * What of the team's findings the actor reads in a perimeter (404 out of sight).
     *
     * @throws ResourceNotFound
     */
    public function filter(string $teamId, ExposureScope $scope, ?string $id): ExposureFilter
    {
        $visible = $this->queries->ask(new GetVisibleProjects($teamId));
        $restricted = $visible->all ? null : $visible->projectIds;
        $id ??= '';

        return match ($scope) {
            ExposureScope::Team => new ExposureFilter($teamId, $restricted),
            ExposureScope::Project => $visible->sees($id) ? new ExposureFilter($teamId, $restricted, projectId: $id) : throw ResourceNotFound::of('project'),
            ExposureScope::Environment => $this->environment($teamId, $id, $visible->all, $restricted),
            ExposureScope::Host => $visible->all ? new ExposureFilter($teamId, hostId: $id) : throw ResourceNotFound::of('host'),
        };
    }

    public function mayAccept(string $teamId, ?string $projectId): bool
    {
        return null === $projectId
            ? $this->permissions->isGranted(Permission::HostApprove, $teamId)
            : $this->permissions->isGranted(Permission::FindingAcceptRisk, $teamId, $projectId);
    }

    /**
     * @throws PermissionDenied
     */
    public function assertMayAccept(string $teamId, ?string $projectId): void
    {
        if (!$this->mayAccept($teamId, $projectId)) {
            throw PermissionDenied::for((null === $projectId ? Permission::HostApprove : Permission::FindingAcceptRisk)->value);
        }
    }

    /**
     * @param ?list<string> $restricted
     */
    private function environment(string $teamId, string $environmentId, bool $all, ?array $restricted): ExposureFilter
    {
        foreach ($this->queries->ask(new ListTeamEnvironments($teamId)) as $environment) {
            if ($environment->environmentId === $environmentId && ($all || \in_array($environment->projectId, $restricted ?? [], true))) {
                return new ExposureFilter($teamId, $restricted, environmentId: $environmentId);
            }
        }

        throw ResourceNotFound::of('environment');
    }

    private function projectOf(string $teamId, string $applicationId): ?string
    {
        $placement = $this->queries->ask(new GetApplicationPlacement($applicationId));
        if (null === $placement || $placement['teamId'] !== $teamId) {
            throw ResourceNotFound::of('finding');
        }

        foreach ($this->queries->ask(new ListTeamEnvironments($teamId)) as $environment) {
            if ($environment->environmentId === $placement['environmentId']) {
                return $environment->projectId;
            }
        }

        return null;
    }
}
