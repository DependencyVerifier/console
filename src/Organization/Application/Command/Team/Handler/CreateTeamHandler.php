<?php

declare(strict_types=1);

namespace Organization\Application\Command\Team\Handler;

use Organization\Application\Command\Team\CreateTeam;
use Organization\Application\Security\OrganizationAccess;
use Organization\Domain\Model\Invitation;
use Organization\Domain\Model\InvitationId;
use Organization\Domain\Model\InvitationTarget;
use Organization\Domain\Model\QuotaName;
use Organization\Domain\Model\QuotaPolicy;
use Organization\Domain\Model\Team;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Model\TeamRole;
use Organization\Domain\Repository\InstanceSettingsRepository;
use Organization\Domain\Repository\InvitationRepository;
use Organization\Domain\Repository\TeamRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Crypto\TokenGenerator;
use Shared\Application\Crypto\TokenHasher;
use Shared\Application\Text\SlugGenerator;
use Shared\Domain\Authorization\Permission;

/**
 * Decision 14: the first TEAM_ADMIN is invited when the team is created.
 */
final readonly class CreateTeamHandler implements CommandHandler
{
    public function __construct(
        private OrganizationAccess $access,
        private TeamRepository $teams,
        private InstanceSettingsRepository $settings,
        private InvitationRepository $invitations,
        private SlugGenerator $slugs,
        private TokenGenerator $tokens,
        private TokenHasher $hasher,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CreateTeam $command): void
    {
        $this->access->assertInstance(Permission::TeamCreate);
        $now = $this->clock->now();
        $teamId = TeamId::fromString($command->teamId);
        $slug = $this->slugs->unique($command->name, $this->teams->slugExists(...));
        $team = Team::create($teamId, $command->name, $slug, $this->settings->current()->defaultQuotas(), $now);
        QuotaPolicy::assertAllows($team, QuotaName::MaxMembers, 0);
        $this->teams->add($team);

        $token = $this->tokens->urlToken();
        $this->invitations->add(Invitation::issue(
            InvitationId::fromString($command->invitationId),
            $command->firstAdminEmail,
            InvitationTarget::team($teamId, TeamRole::Admin),
            $this->access->actorId(),
            $token,
            $this->hasher->hash($token),
            $now,
        ));
    }
}
