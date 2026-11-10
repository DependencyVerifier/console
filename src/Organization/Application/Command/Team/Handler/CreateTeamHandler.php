<?php

declare(strict_types=1);

namespace Organization\Application\Command\Team\Handler;

use Organization\Application\Command\Team\CreateTeam;
use Organization\Application\Security\OrganizationAccess;
use Organization\Domain\Model\GrantChange;
use Organization\Domain\Model\PermissionGrant;
use Organization\Domain\Model\QuotaName;
use Organization\Domain\Model\QuotaPolicy;
use Organization\Domain\Model\Team;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Model\TeamRole;
use Organization\Domain\Repository\InstanceSettingsRepository;
use Organization\Domain\Repository\PermissionGrantRepository;
use Organization\Domain\Repository\TeamRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Contract\Identity\AccountProvisioning;
use Shared\Application\Text\SlugGenerator;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;

/**
 * Decision 14, revised: the first TEAM_ADMIN is admitted when the team is created, without invitation. Its account
 * comes from Identity's port, in this same transaction: a refused account (deactivated…) leaves no team, a refused
 * team (quota) leaves no invited account.
 */
final readonly class CreateTeamHandler implements CommandHandler
{
    public function __construct(
        private OrganizationAccess $access,
        private TeamRepository $teams,
        private InstanceSettingsRepository $settings,
        private PermissionGrantRepository $grants,
        private AccountProvisioning $accounts,
        private SlugGenerator $slugs,
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

        $account = $this->accounts->teamAdminAccount($command->firstAdminEmail, $command->firstAdminUserId, $command->setupRequestId);
        $adminId = Uuid::fromString($account->userId);
        $team->appointFirstAdmin($adminId, $now);
        $this->teams->add($team);
        $this->grants->add(PermissionGrant::record($teamId, null, $adminId, GrantChange::RoleAssigned, TeamRole::Admin->value, $this->access->actorId(), $now));
    }
}
