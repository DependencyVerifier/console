<?php

declare(strict_types=1);

namespace Organization\Application\Query;

use Organization\Application\Query\View\EnvironmentRow;
use Organization\Application\Query\View\InvitationRow;
use Organization\Application\Query\View\MemberRow;
use Organization\Application\Query\View\ProjectRow;
use Organization\Application\Query\View\TeamSummary;
use Shared\Application\Contract\Organization\PendingInvitation;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

/**
 * Read side of Organization (DBAL). It filters by the ids it is given; the handlers decide which ids the actor may
 * see. Member emails come from Identity through the FindAccounts contract, not from this model.
 */
interface OrganizationReadModel
{
    /**
     * @param list<string>|null $teamIds null: every team (super admin)
     *
     * @return Page<TeamSummary>
     */
    public function teams(?array $teamIds, PageRequest $page): Page;

    public function team(string $teamId): ?TeamSummary;

    public function activeMembers(string $teamId): int;

    public function pendingInvitations(string $teamId, \DateTimeImmutable $now): int;

    public function projectCount(string $teamId): int;

    /**
     * @return Page<MemberRow> rows with an empty email (filled by the handler)
     */
    public function teamMembers(string $teamId, PageRequest $page): Page;

    /**
     * @return Page<MemberRow>
     */
    public function projectMembers(string $projectId, PageRequest $page): Page;

    /**
     * @return Page<InvitationRow>
     */
    public function invitations(string $teamId, PageRequest $page, \DateTimeImmutable $now): Page;

    /**
     * @param list<string>|null $projectIds null: every project of the team
     *
     * @return Page<ProjectRow>
     */
    public function projects(string $teamId, ?array $projectIds, PageRequest $page): Page;

    /**
     * @return Page<EnvironmentRow>
     */
    public function environments(string $projectId, PageRequest $page): Page;

    public function pendingInvitation(?string $tokenHash, ?string $invitationId, \DateTimeImmutable $now): ?PendingInvitation;
}
