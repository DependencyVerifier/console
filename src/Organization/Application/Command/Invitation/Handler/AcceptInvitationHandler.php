<?php

declare(strict_types=1);

namespace Organization\Application\Command\Invitation\Handler;

use Organization\Domain\Model\GrantChange;
use Organization\Domain\Model\InvitationId;
use Organization\Domain\Model\InvitationNotPending;
use Organization\Domain\Model\PermissionGrant;
use Organization\Domain\Model\ProjectId;
use Organization\Domain\Model\QuotaName;
use Organization\Domain\Model\QuotaPolicy;
use Organization\Domain\Model\Team;
use Organization\Domain\Model\TeamRole;
use Organization\Domain\Repository\InvitationRepository;
use Organization\Domain\Repository\PermissionGrantRepository;
use Organization\Domain\Repository\ProjectRepository;
use Organization\Domain\Repository\TeamRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Identity\FindAccounts;
use Shared\Application\Contract\Organization\AcceptInvitation;
use Shared\Application\Security\CurrentActor;
use Symfony\Component\Uid\Uuid;

/**
 * The signed-in invitee accepts: team locked, invitation checked (pending, same email), quota checked again, then the
 * membership (plus TEAM_GUEST for a project invitation of a non-member) and the grant history. Idempotent: an existing
 * membership only marks the invitation accepted.
 */
final readonly class AcceptInvitationHandler implements CommandHandler
{
    public function __construct(
        private CurrentActor $currentActor,
        private QueryBus $queries,
        private InvitationRepository $invitations,
        private TeamRepository $teams,
        private ProjectRepository $projects,
        private PermissionGrantRepository $grants,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(AcceptInvitation $command): void
    {
        $now = $this->clock->now();
        $userId = $this->currentActor->actor()->userId();
        $invitation = $this->invitations->find(InvitationId::fromString($command->invitationId)) ?? throw new InvitationNotPending();
        $target = $invitation->target();
        $team = $this->teams->lockById($target->teamId()) ?? throw new InvitationNotPending();
        $account = $this->queries->ask(new FindAccounts([$userId->toRfc4122()]))[$userId->toRfc4122()] ?? throw new InvitationNotPending();

        $pendingOthers = $this->invitations->countPendingOfTeam($target->teamId(), $now) - 1;
        $invitation->accept($account->email, $userId, $now);

        if (!$team->isMember($userId)) {
            QuotaPolicy::assertAllows($team, QuotaName::MaxMembers, $team->activeMemberCount() + max(0, $pendingOthers));
            $role = $target->isProject() ? TeamRole::Guest : $target->teamRole();
            $team->admit($userId, $role, $now);
            $this->record($team, null, $userId, $role->value, $invitation->invitedBy(), $now);
        }

        if ($target->isProject()) {
            $project = $this->projects->find($target->projectId()) ?? throw new InvitationNotPending();
            if (!$project->isMember($userId)) {
                $project->admit($userId, $target->projectRole(), $now);
                $this->record($team, $target->projectId(), $userId, $target->roleValue(), $invitation->invitedBy(), $now);
            }
        }
    }

    private function record(Team $team, ?ProjectId $projectId, Uuid $userId, string $role, Uuid $grantedBy, \DateTimeImmutable $now): void
    {
        $this->grants->add(PermissionGrant::record($team->id(), $projectId, $userId, GrantChange::RoleAssigned, $role, $grantedBy, $now));
    }
}
