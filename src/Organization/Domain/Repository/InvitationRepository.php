<?php

declare(strict_types=1);

namespace Organization\Domain\Repository;

use Organization\Domain\Model\Invitation;
use Organization\Domain\Model\InvitationId;
use Organization\Domain\Model\InvitationTarget;
use Organization\Domain\Model\ProjectId;
use Organization\Domain\Model\TeamId;

interface InvitationRepository
{
    public function find(InvitationId $id): ?Invitation;

    public function findByTokenHash(string $tokenHash): ?Invitation;

    public function add(Invitation $invitation): void;

    /**
     * The invitation still in status pending (expired or not) for this email and target.
     */
    public function findOpen(string $email, InvitationTarget $target): ?Invitation;

    /**
     * Pending and not expired invitations of the team (they count in maxMembers).
     */
    public function countPendingOfTeam(TeamId $teamId, \DateTimeImmutable $now): int;

    /**
     * @return list<Invitation>
     */
    public function openOfProject(ProjectId $projectId): array;
}
