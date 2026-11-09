<?php

declare(strict_types=1);

namespace Organization\Application\Command\Invitation\Handler;

use Organization\Application\Command\Invitation\InviteMember;
use Organization\Application\Security\OrganizationAccess;
use Organization\Application\Security\RoleAuthority;
use Organization\Domain\Model\Invitation;
use Organization\Domain\Model\InvitationAlreadyPending;
use Organization\Domain\Model\InvitationId;
use Organization\Domain\Model\InvitationTarget;
use Organization\Domain\Model\ProjectId;
use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\QuotaName;
use Organization\Domain\Model\QuotaPolicy;
use Organization\Domain\Model\RoleNotAssignable;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Model\TeamRole;
use Organization\Domain\Repository\InvitationRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Crypto\TokenGenerator;
use Shared\Application\Crypto\TokenHasher;
use Shared\Domain\Authorization\Permission;

/**
 * Always a link to accept, even for an existing account (nothing revealed to the inviter). An expired invitation
 * still pending for the same email and target is revoked first. Quota maxMembers: active members + pending
 * invitations, checked here and again at acceptance.
 */
final readonly class InviteMemberHandler implements CommandHandler
{
    public function __construct(
        private OrganizationAccess $access,
        private RoleAuthority $roles,
        private InvitationRepository $invitations,
        private TokenGenerator $tokens,
        private TokenHasher $hasher,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(InviteMember $command): void
    {
        $now = $this->clock->now();
        $teamId = TeamId::fromString($command->teamId);
        $team = $this->access->lockTeam($teamId);
        $target = null === $command->projectId ? $this->teamTarget($teamId, $command->role) : $this->projectTarget($teamId, $command->projectId, $command->role);

        $email = Invitation::normalize($command->email);
        $open = $this->invitations->findOpen($email, $target);
        if (null !== $open && $open->isPendingAt($now)) {
            throw new InvitationAlreadyPending();
        }

        $open?->revoke();
        QuotaPolicy::assertAllows($team, QuotaName::MaxMembers, $team->activeMemberCount() + $this->invitations->countPendingOfTeam($teamId, $now));

        $token = $this->tokens->urlToken();
        $this->invitations->add(Invitation::issue(InvitationId::fromString($command->invitationId), $email, $target, $this->access->actorId(), $token, $this->hasher->hash($token), $now));
    }

    private function teamTarget(TeamId $teamId, string $role): InvitationTarget
    {
        $this->access->assert(Permission::TeamMembersManage, $teamId);
        $teamRole = TeamRole::tryFrom($role) ?? throw new RoleNotAssignable();
        $this->roles->assertTeamRole($teamId, $teamRole);

        return InvitationTarget::team($teamId, $teamRole);
    }

    private function projectTarget(TeamId $teamId, string $projectId, string $role): InvitationTarget
    {
        $id = ProjectId::fromString($projectId);
        $project = $this->access->project($id);
        if (!$project->teamId()->equals($teamId)) {
            throw ResourceNotFound::of('project');
        }

        $this->access->assert(Permission::ProjectMembersManage, $teamId, $id);
        $projectRole = ProjectRole::tryFrom($role) ?? throw new RoleNotAssignable();
        $this->roles->assertProjectRole($teamId, $id, $projectRole);

        return InvitationTarget::project($teamId, $id, $projectRole);
    }
}
