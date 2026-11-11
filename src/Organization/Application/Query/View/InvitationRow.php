<?php

declare(strict_types=1);

namespace Organization\Application\Query\View;

final readonly class InvitationRow
{
    public function __construct(
        public string $id,
        public string $email,
        public string $role,
        public ?string $projectName,
        public bool $expired,
        public \DateTimeImmutable $expiresAt,
        public string $teamId,
        public string $teamName,
    ) {
    }
}
