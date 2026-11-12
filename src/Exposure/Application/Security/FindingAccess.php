<?php

declare(strict_types=1);

namespace Exposure\Application\Security;

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
