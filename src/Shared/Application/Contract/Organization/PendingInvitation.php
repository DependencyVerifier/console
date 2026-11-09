<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Organization;

final readonly class PendingInvitation
{
    public function __construct(
        public string $invitationId,
        public string $teamId,
        public string $email,
        public string $teamName,
        public ?string $projectName,
        public string $role,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
