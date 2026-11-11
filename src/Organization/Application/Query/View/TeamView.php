<?php

declare(strict_types=1);

namespace Organization\Application\Query\View;

/**
 * A team as its members see it, with the gestures the actor may do (UI hints only: every handler checks again).
 */
final readonly class TeamView
{
    /**
     * @param list<QuotaUsage>    $quotas
     * @param array<string, bool> $can    permission value => granted
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public ?string $role,
        public bool $adminEstablished,
        public array $quotas,
        public array $can,
        public int $memberCount,
        public int $projectCount,
        public int $pendingInvitationCount,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * A creation gated by this quota is refused: its button is disabled (the handler refuses anyway).
     */
    public function quotaReached(string $name): bool
    {
        foreach ($this->quotas as $quota) {
            if ($quota->name === $name) {
                return $quota->isReached();
            }
        }

        return false;
    }

    public function can(string $permission): bool
    {
        return $this->can[$permission] ?? false;
    }
}
