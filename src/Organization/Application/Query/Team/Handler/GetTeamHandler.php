<?php

declare(strict_types=1);

namespace Organization\Application\Query\Team\Handler;

use Organization\Application\Query\OrganizationReadModel;
use Organization\Application\Query\Team\GetTeam;
use Organization\Application\Query\View\QuotaUsage;
use Organization\Application\Query\View\TeamView;
use Organization\Application\Security\ActorPermissions;
use Organization\Domain\Model\QuotaName;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Repository\TeamRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Quota\QuotaUsageProvider;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * A team for its members (404 for anyone else), with its quota gauges (a usage only for the enforced quotas: those of
 * Organization, and those counted by the other contexts' QuotaUsageProvider) and the gestures the actor may do.
 */
final readonly class GetTeamHandler implements QueryHandler
{
    private const array GESTURES = [
        Permission::TeamEdit, Permission::TeamMembersManage, Permission::TeamAuditView, Permission::ProjectCreate,
        Permission::TeamQuotasManage, Permission::HostEdit,
    ];

    /**
     * @param iterable<QuotaUsageProvider> $usageProviders
     */
    public function __construct(
        private ActorPermissions $permissions,
        private TeamRepository $teams,
        private OrganizationReadModel $read,
        private ClockInterface $clock,
        #[AutowireIterator(QuotaUsageProvider::class)]
        private iterable $usageProviders = [],
    ) {
    }

    public function __invoke(GetTeam $query): TeamView
    {
        $permissions = $this->permissions->current();
        $team = $this->teams->find(TeamId::fromString($query->teamId));
        if (null === $team || !$permissions->isTeamMember($query->teamId)) {
            throw ResourceNotFound::of('team');
        }

        $projects = $this->read->projectCount($query->teamId);
        $members = $this->read->activeMembers($query->teamId);
        $invitations = $this->read->pendingInvitations($query->teamId, $this->clock->now());
        $usage = [
            QuotaName::MaxProjects->value => $projects,
            QuotaName::MaxMembers->value => $members + $invitations,
        ];
        foreach ($this->usageProviders as $provider) {
            foreach ($provider->quotas() as $quota) {
                $usage[$quota] = $provider->usage($query->teamId, $quota);
            }
        }

        $quotas = [];
        foreach (QuotaName::cases() as $name) {
            $quotas[] = new QuotaUsage($name->value, $team->quotas()->get($name)->toStorage(), $usage[$name->value] ?? 0, isset($usage[$name->value]));
        }

        $can = [];
        foreach (self::GESTURES as $gesture) {
            $can[$gesture->value] = $permissions->allows($gesture, $query->teamId);
        }

        return new TeamView($query->teamId, $team->name(), $team->slug(), $permissions->teamRole($query->teamId)?->value, $team->isAdminEstablished(), $quotas, $can, $members, $projects, $invitations, $team->createdAt());
    }
}
