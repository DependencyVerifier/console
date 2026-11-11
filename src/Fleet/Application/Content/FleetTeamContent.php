<?php

declare(strict_types=1);

namespace Fleet\Application\Content;

use Fleet\Domain\Repository\AgentOrderRepository;
use Fleet\Domain\Repository\AgentRepository;
use Fleet\Domain\Repository\EnrollmentTokenRepository;
use Fleet\Domain\Repository\HostRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Content\ProjectContentProbe;
use Shared\Application\Content\TeamContentEraser;
use Symfony\Component\Uid\Uuid;

/**
 * What Fleet holds for Organization's objects:
 * - a deleted team takes its orders, agents, hosts and tokens with it (its agents then get unknown_agent and stop);
 * - an environment that a usable token enrolls into cannot be deleted (revoke the token first).
 */
final readonly class FleetTeamContent implements TeamContentEraser, ProjectContentProbe
{
    public function __construct(
        private EnrollmentTokenRepository $tokens,
        private HostRepository $hosts,
        private AgentRepository $agents,
        private AgentOrderRepository $orders,
        private ClockInterface $clock,
    ) {
    }

    public function eraseTeam(string $teamId): void
    {
        $team = Uuid::fromString($teamId);
        $this->orders->deleteOfTeam($team);
        $this->agents->deleteOfTeam($team);
        $this->hosts->deleteOfTeam($team);
        $this->tokens->deleteOfTeam($team);
    }

    public function projectHasContent(string $projectId): bool
    {
        return false; // a project is deleted once it has no environment: its tokens are checked there
    }

    public function environmentHasContent(string $environmentId): bool
    {
        return Uuid::isValid($environmentId) && $this->tokens->hasActiveForEnvironment(Uuid::fromString($environmentId), $this->clock->now());
    }
}
