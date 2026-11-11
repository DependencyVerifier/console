<?php

declare(strict_types=1);

namespace Tests\Fleet\Support;

use Fleet\Domain\Model\Agent;
use Fleet\Domain\Model\AgentId;
use Fleet\Domain\Model\AgentStatus;
use Fleet\Domain\Model\EnrollmentTokenId;
use Fleet\Domain\Model\HostId;
use Fleet\Domain\Repository\AgentRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryAgents implements AgentRepository
{
    /** @var array<string, Agent> */
    public array $agents = [];

    public function find(AgentId $id): ?Agent
    {
        return $this->agents[$id->toString()] ?? null;
    }

    public function findLiveByFingerprint(string $fingerprint): ?Agent
    {
        return array_find($this->agents, static fn (Agent $a): bool => $a->fingerprint() === $fingerprint && AgentStatus::Revoked !== $a->status());
    }

    public function findByFingerprint(string $fingerprint): ?Agent
    {
        return $this->findLiveByFingerprint($fingerprint) ?? array_find($this->agents, static fn (Agent $a): bool => $a->fingerprint() === $fingerprint);
    }

    public function findEnrolled(EnrollmentTokenId $tokenId, string $fingerprint): ?Agent
    {
        return array_find($this->agents, static fn (Agent $a): bool => $a->fingerprint() === $fingerprint && $a->enrollmentTokenId()->value->equals($tokenId->value));
    }

    public function ofToken(EnrollmentTokenId $tokenId): array
    {
        return array_values(array_filter($this->agents, static fn (Agent $a): bool => $a->enrollmentTokenId()->value->equals($tokenId->value)));
    }

    public function add(Agent $agent): void
    {
        $this->agents[$agent->id()->toString()] = $agent;
    }

    public function remove(Agent $agent): void
    {
        unset($this->agents[$agent->id()->toString()]);
    }

    public function countInQuota(Uuid $teamId): int
    {
        return \count(array_filter($this->agents, static fn (Agent $a): bool => $a->teamId()->equals($teamId) && $a->status()->countsInQuota()));
    }

    public function ofHost(HostId $hostId): array
    {
        return array_values(array_filter($this->agents, static fn (Agent $a): bool => $a->hostId()->value->equals($hostId->value)));
    }

    public function inactiveSilentSince(\DateTimeImmutable $before, int $limit): array
    {
        return \array_slice(array_values(array_filter($this->agents, static fn (Agent $a): bool => $a->isInactive() && $a->lastSeenAt() <= $before)), 0, $limit);
    }

    public function approvedSilentSince(\DateTimeImmutable $before, int $limit): array
    {
        return \array_slice(array_values(array_filter($this->agents, static fn (Agent $a): bool => $a->isApproved() && $a->lastSeenAt() <= $before)), 0, $limit);
    }

    public function deleteOfTeam(Uuid $teamId): void
    {
        $this->agents = array_filter($this->agents, static fn (Agent $x): bool => !$x->teamId()->equals($teamId));
    }
}
