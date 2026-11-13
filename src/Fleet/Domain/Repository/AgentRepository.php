<?php

declare(strict_types=1);

namespace Fleet\Domain\Repository;

use Fleet\Domain\Model\Agent;
use Fleet\Domain\Model\AgentId;
use Fleet\Domain\Model\EnrollmentTokenId;
use Fleet\Domain\Model\HostId;
use Symfony\Component\Uid\Uuid;

interface AgentRepository
{
    public function find(AgentId $id): ?Agent;

    /**
     * The agent not revoked holding this key fingerprint (`keyid` of the signed requests).
     */
    public function findLiveByFingerprint(string $fingerprint): ?Agent;

    /**
     * The agent holding this key fingerprint: the live one, else the last revoked one (to answer agent_revoked).
     */
    public function findByFingerprint(string $fingerprint): ?Agent;

    /**
     * The agent enrolled with this token and key (an idempotent retry of the enrollment), whatever its status.
     */
    public function findEnrolled(EnrollmentTokenId $tokenId, string $fingerprint): ?Agent;

    /**
     * @return list<Agent>
     */
    public function ofToken(EnrollmentTokenId $tokenId): array;

    /**
     * @return list<Agent> the agents of a team
     */
    public function ofTeam(Uuid $teamId): array;

    /**
     * @return list<Agent> every approved agent of the instance
     */
    public function approved(): array;

    public function add(Agent $agent): void;

    public function remove(Agent $agent): void;

    /**
     * Pending and approved agents of the team (maxAgents).
     */
    public function countInQuota(Uuid $teamId): int;

    /**
     * @return list<Agent>
     */
    public function ofHost(HostId $hostId): array;

    /**
     * Inactive agents silent since $before (candidates to revocation).
     *
     * @return list<Agent>
     */
    public function inactiveSilentSince(\DateTimeImmutable $before, int $limit): array;

    /**
     * Approved agents silent since $before (candidates to inactive).
     *
     * @return list<Agent>
     */
    public function approvedSilentSince(\DateTimeImmutable $before, int $limit): array;

    /**
     * Every row of the team (DeleteTeam cascade).
     */
    public function deleteOfTeam(Uuid $teamId): void;
}
