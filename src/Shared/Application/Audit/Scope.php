<?php

declare(strict_types=1);

namespace Shared\Application\Audit;

/**
 * Team and project an entry belongs to, resolved from the real target (never supplied by the client).
 * It drives the team audit view (`team.audit.view`).
 */
final readonly class Scope
{
    private function __construct(private ?string $teamId, private ?string $projectId)
    {
    }

    public static function none(): self
    {
        return new self(null, null);
    }

    public static function team(string $teamId): self
    {
        return new self($teamId, null);
    }

    public static function project(string $teamId, string $projectId): self
    {
        return new self($teamId, $projectId);
    }

    public function teamIdForStorage(): ?string
    {
        return $this->teamId;
    }

    public function projectIdForStorage(): ?string
    {
        return $this->projectId;
    }
}
