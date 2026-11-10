<?php

declare(strict_types=1);

namespace Organization\Application\Query\Member\Handler;

use Organization\Application\Query\Member\GetMemberPermissions;
use Organization\Application\Query\View\MemberPermissions;
use Organization\Application\Query\View\PermissionChoice;
use Organization\Application\Security\ActorPermissions;
use Organization\Domain\Authorization\RolePermissions;
use Organization\Domain\Model\NotAMember;
use Organization\Domain\Model\ProjectId;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Repository\ProjectRepository;
use Organization\Domain\Repository\TeamRepository;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryHandler;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;

/**
 * 404 out of scope or for someone who is not a member, 403 without the right to manage the members of that scope.
 */
final readonly class GetMemberPermissionsHandler implements QueryHandler
{
    public function __construct(private ActorPermissions $permissions, private TeamRepository $teams, private ProjectRepository $projects)
    {
    }

    public function __invoke(GetMemberPermissions $query): MemberPermissions
    {
        $actor = $this->permissions->current();
        $user = Uuid::fromString($query->userId);
        if (null === $query->projectId) {
            $team = $this->teams->find(TeamId::fromString($query->teamId));
            if (null === $team || !$actor->isTeamMember($query->teamId)) {
                throw ResourceNotFound::of('team');
            }

            if (!$actor->allows(Permission::TeamMembersManage, $query->teamId)) {
                throw PermissionDenied::for(Permission::TeamMembersManage->value);
            }

            $membership = $this->membership(static fn () => $team->membershipOf($user));
            $role = $membership->role();

            return $this->view($query, $role->value, RolePermissions::grantableToTeamRole($role), $membership->extraPermissions());
        }

        $project = $this->projects->find(ProjectId::fromString($query->projectId));
        if (null === $project || !$project->teamId()->equals(TeamId::fromString($query->teamId)) || !$actor->seesProject($query->teamId, $query->projectId)) {
            throw ResourceNotFound::of('project');
        }

        if (!$actor->allows(Permission::ProjectMembersManage, $query->teamId, $query->projectId)) {
            throw PermissionDenied::for(Permission::ProjectMembersManage->value);
        }

        $membership = $this->membership(static fn () => $project->membershipOf($user));
        $role = $membership->role();

        return $this->view($query, $role->value, RolePermissions::grantableToProjectRole($role), $membership->extraPermissions());
    }

    /**
     * @template T
     *
     * @param \Closure(): T $find
     *
     * @return T
     */
    private function membership(\Closure $find): mixed
    {
        try {
            return $find();
        } catch (NotAMember) {
            throw ResourceNotFound::of('member');
        }
    }

    /**
     * @param list<Permission> $grantable
     * @param list<Permission> $granted
     */
    private function view(GetMemberPermissions $query, string $role, array $grantable, array $granted): MemberPermissions
    {
        $actor = $this->permissions->current();

        return new MemberPermissions($query->userId, $role, array_map(static fn (Permission $p): PermissionChoice => new PermissionChoice(
            $p->value,
            \in_array($p, $granted, true),
            $actor->allows($p, $query->teamId, $query->projectId),
        ), $grantable));
    }
}
