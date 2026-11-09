<?php

declare(strict_types=1);

namespace Tests\Organization\Support;

use Organization\Domain\Model\Invitation;
use Organization\Domain\Model\InvitationId;
use Organization\Domain\Model\InvitationTarget;
use Organization\Domain\Model\ProjectId;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Repository\InvitationRepository;

final class InMemoryInvitations implements InvitationRepository
{
    /** @var array<string, Invitation> */
    public array $invitations = [];

    public int $pendingOfTeam = 0;

    public function find(InvitationId $id): ?Invitation
    {
        return $this->invitations[$id->toString()] ?? null;
    }

    public function findByTokenHash(string $tokenHash): ?Invitation
    {
        return null;
    }

    public function add(Invitation $invitation): void
    {
        $this->invitations[$invitation->id()->toString()] = $invitation;
    }

    public function findOpen(string $email, InvitationTarget $target): ?Invitation
    {
        foreach ($this->invitations as $invitation) {
            if ($invitation->email() === $email && $invitation->target()->key() === $target->key() && !$invitation->isAccepted() && !$invitation->isRevoked()) {
                return $invitation;
            }
        }

        return null;
    }

    public function countPendingOfTeam(TeamId $teamId, \DateTimeImmutable $now): int
    {
        return $this->pendingOfTeam;
    }

    public function openOfProject(ProjectId $projectId): array
    {
        return array_values(array_filter($this->invitations, static fn (Invitation $i): bool => $i->target()->isProject() && $i->target()->projectId()->equals($projectId) && !$i->isAccepted() && !$i->isRevoked()));
    }
}
