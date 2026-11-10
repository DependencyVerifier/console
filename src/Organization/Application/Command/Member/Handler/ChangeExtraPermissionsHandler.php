<?php

declare(strict_types=1);

namespace Organization\Application\Command\Member\Handler;

use Organization\Application\Command\Member\ChangeExtraPermissions;
use Organization\Application\Security\OrganizationAccess;
use Organization\Domain\Authorization\RolePermissions;
use Organization\Domain\Model\PermissionNotGrantable;
use Organization\Domain\Model\ProjectId;
use Organization\Domain\Model\TeamId;
use Shared\Application\Bus\CommandHandler;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;

/**
 * Compares the wanted set with the member's current extras and applies each difference through ExtraPermissionChange
 * (scope, rank, holding and the grant history, as for a single grant). A permission outside the ○ of the role refuses
 * the whole change.
 */
final readonly class ChangeExtraPermissionsHandler implements CommandHandler
{
    public function __construct(private OrganizationAccess $access, private ExtraPermissionChange $change)
    {
    }

    public function __invoke(ChangeExtraPermissions $command): void
    {
        $user = Uuid::fromString($command->userId);
        if (null === $command->projectId) {
            $membership = $this->access->lockTeam(TeamId::fromString($command->teamId))->membershipOf($user);
            [$grantable, $current] = [RolePermissions::grantableToTeamRole($membership->role()), $membership->extraPermissions()];
        } else {
            // Same order as a single change: the project (404 out of scope), its real team, then the team lock.
            $project = $this->access->project(ProjectId::fromString($command->projectId));
            if (!$project->teamId()->equals(TeamId::fromString($command->teamId))) {
                throw new PermissionNotGrantable();
            }

            $this->access->lockTeam($project->teamId());
            $membership = $project->membershipOf($user);
            [$grantable, $current] = [RolePermissions::grantableToProjectRole($membership->role()), $membership->extraPermissions()];
        }

        $wanted = [];
        foreach ($command->permissions as $value) {
            $permission = Permission::tryFrom($value);
            if (null === $permission || !\in_array($permission, $grantable, true)) {
                throw new PermissionNotGrantable();
            }

            $wanted[] = $permission;
        }

        foreach ($grantable as $permission) {
            $want = \in_array($permission, $wanted, true);
            if ($want !== \in_array($permission, $current, true)) {
                $this->change->apply($command->teamId, $command->projectId, $command->userId, $permission->value, $want);
            }
        }
    }
}
