<?php

declare(strict_types=1);

namespace Tests\Notification\Support;

use Notification\Domain\Model\RecipientInvitation;
use Notification\Domain\Model\RecipientInvitationStatus;
use Notification\Domain\Repository\RecipientInvitationRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryRecipientInvitations implements RecipientInvitationRepository
{
    /** @var list<RecipientInvitation> */
    public array $invitations = [];

    public function find(Uuid $id): ?RecipientInvitation
    {
        return array_find($this->invitations, static fn (RecipientInvitation $i): bool => $i->id()->equals($id));
    }

    public function findByTokenHash(string $hash): ?RecipientInvitation
    {
        return array_find($this->invitations, static fn (RecipientInvitation $i): bool => $hash === $i->tokenHash());
    }

    public function pending(Uuid $projectId, string $email): ?RecipientInvitation
    {
        return array_find($this->invitations, static fn (RecipientInvitation $i): bool => $i->projectId()->equals($projectId) && $email === $i->email() && RecipientInvitationStatus::Pending === $i->status());
    }

    public function pendingOfProject(Uuid $projectId, \DateTimeImmutable $now): array
    {
        return array_values(array_filter($this->invitations, static fn (RecipientInvitation $i): bool => $i->projectId()->equals($projectId) && $i->isPendingAt($now)));
    }

    public function pendingEmailsOfTeam(Uuid $teamId, \DateTimeImmutable $now): array
    {
        $emails = array_values(array_unique(array_map(static fn (RecipientInvitation $i): string => $i->email(), array_filter($this->invitations, static fn (RecipientInvitation $i): bool => $i->teamId()->equals($teamId) && $i->isPendingAt($now)))));
        sort($emails);

        return $emails;
    }

    public function add(RecipientInvitation $invitation): void
    {
        $this->invitations[] = $invitation;
    }

    public function deleteTeam(Uuid $teamId): void
    {
        $this->invitations = array_values(array_filter($this->invitations, static fn (RecipientInvitation $i): bool => !$i->teamId()->equals($teamId)));
    }
}
