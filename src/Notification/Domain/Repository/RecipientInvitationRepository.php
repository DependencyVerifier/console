<?php

declare(strict_types=1);

namespace Notification\Domain\Repository;

use Notification\Domain\Model\RecipientInvitation;
use Symfony\Component\Uid\Uuid;

interface RecipientInvitationRepository
{
    public function find(Uuid $id): ?RecipientInvitation;

    public function findByTokenHash(string $hash): ?RecipientInvitation;

    /**
     * The pending invitation of an address to a project, expired or not.
     */
    public function pending(Uuid $projectId, string $email): ?RecipientInvitation;

    /**
     * The invitations of a project still pending at that time, the oldest first.
     *
     * @return list<RecipientInvitation>
     */
    public function pendingOfProject(Uuid $projectId, \DateTimeImmutable $now): array;

    /**
     * The addresses invited to a project of the team and still pending at that time (each once).
     *
     * @return list<string>
     */
    public function pendingEmailsOfTeam(Uuid $teamId, \DateTimeImmutable $now): array;

    public function add(RecipientInvitation $invitation): void;

    public function deleteTeam(Uuid $teamId): void;
}
