<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Fleet\Domain\Event\AgentApproved;
use Fleet\Domain\Event\AgentEnrolled;
use Fleet\Domain\Event\AgentRevoked;
use Shared\Domain\Model\EventRecording;
use Shared\Domain\Model\RecordsEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Agent aggregate (docs/cadrage.md §6, protocol §5–§6): one Ed25519 key pair, enrolled with a token on a host of a
 * team. Every agent is approved by a human, except those enrolled with an approved reusable token. A revoked agent
 * never comes back (a new installation brings a new key). Its fingerprint is unique among the agents not revoked.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fleet_agent')]
#[ORM\Index(name: 'fleet_agent_team', columns: ['team_id'])]
#[ORM\Index(name: 'fleet_agent_host', columns: ['host_id'])]
#[ORM\UniqueConstraint(name: 'fleet_agent_token_key', columns: ['enrollment_token_id', 'fingerprint'])]
#[ORM\UniqueConstraint(name: 'fleet_agent_live_fingerprint', columns: ['fingerprint'], options: ['where' => "((status)::text <> 'revoked'::text)"])]
class Agent implements RecordsEvents
{
    use EventRecording;

    public const string INACTIVE_AFTER = '-7 days';

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $approvedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column]
    private int $appliedConfigVersion = 0;

    #[ORM\Column]
    private int $desiredConfigVersion = 0;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $desiredConfig = null;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\Column(type: 'uuid')]
        private Uuid $teamId,
        #[ORM\Column(type: 'uuid')]
        private Uuid $hostId,
        #[ORM\Column(type: 'uuid')]
        private Uuid $enrollmentTokenId,
        #[ORM\Column(length: 60)]
        private string $publicKey,
        #[ORM\Column(length: 60)]
        private string $fingerprint,
        #[ORM\Column(length: 16, enumType: AgentMode::class)]
        private AgentMode $mode,
        #[ORM\Column(length: 16, nullable: true, enumType: AgentScope::class)]
        private ?AgentScope $scope,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $composeProject,
        #[ORM\Column(length: 64)]
        private string $version,
        #[ORM\Column(length: 16, enumType: AgentStatus::class)]
        private AgentStatus $status,
        #[ORM\Column]
        private \DateTimeImmutable $enrolledAt,
    ) {
        $this->lastSeenAt = $enrolledAt;
    }

    /**
     * The token was consumed by the caller under the team lock; its status decides whether the agent is approved.
     */
    public static function enroll(AgentId $id, EnrollmentToken $token, Host $host, AgentPublicKey $key, AgentRuntime $runtime, \DateTimeImmutable $now): self
    {
        $agent = new self(
            $id->value, $token->teamId(), $host->id()->value, $token->id()->value, $key->text(), $key->fingerprint(),
            $runtime->mode, $runtime->scope, $runtime->composeProject, $runtime->version,
            $token->approvesAgents() ? AgentStatus::Approved : AgentStatus::Pending, $now,
        );
        if ($token->approvesAgents()) {
            $agent->approvedAt = $now;
        }

        $agent->recordThat(new AgentEnrolled($id->toString(), $token->teamId()->toRfc4122()));

        return $agent;
    }

    public function id(): AgentId
    {
        return AgentId::of($this->id);
    }

    public function teamId(): Uuid
    {
        return $this->teamId;
    }

    public function hostId(): HostId
    {
        return HostId::of($this->hostId);
    }

    public function enrollmentTokenId(): EnrollmentTokenId
    {
        return EnrollmentTokenId::of($this->enrollmentTokenId);
    }

    public function publicKey(): AgentPublicKey
    {
        return AgentPublicKey::fromText($this->publicKey);
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function runtime(): AgentRuntime
    {
        return new AgentRuntime($this->mode, $this->scope, $this->composeProject, $this->version);
    }

    public function status(): AgentStatus
    {
        return $this->status;
    }

    public function enrolledAt(): \DateTimeImmutable
    {
        return $this->enrolledAt;
    }

    public function lastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function approvedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function approvedBy(): ?Uuid
    {
        return $this->approvedBy;
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isApproved(): bool
    {
        return AgentStatus::Approved === $this->status;
    }

    public function approve(Uuid $by, \DateTimeImmutable $now): void
    {
        if (AgentStatus::Pending !== $this->status) {
            throw new AgentNotPending();
        }

        $this->status = AgentStatus::Approved;
        $this->approvedAt = $now;
        $this->approvedBy = $by;
        $this->recordThat(new AgentApproved($this->id->toRfc4122(), $this->teamId->toRfc4122()));
    }

    /**
     * Idempotent. Refusing a pending agent is revoking it.
     */
    public function revoke(\DateTimeImmutable $now): void
    {
        if (AgentStatus::Revoked === $this->status) {
            return;
        }

        $this->status = AgentStatus::Revoked;
        $this->revokedAt = $now;
        $this->recordThat(new AgentRevoked($this->id->toRfc4122(), $this->teamId->toRfc4122()));
    }

    /**
     * A valid signed request: the agent is alive. An inactive agent must first be reactivated (quota check).
     */
    public function seen(string $version, int $appliedConfigVersion, \DateTimeImmutable $now): void
    {
        if (AgentStatus::Revoked === $this->status) {
            throw new RevokedAgent();
        }

        $this->version = AgentRuntime::version($version);
        $this->appliedConfigVersion = max(0, $appliedConfigVersion);
        $this->lastSeenAt = $now;
    }

    public function isInactive(): bool
    {
        return AgentStatus::Inactive === $this->status;
    }

    public function reactivate(): void
    {
        if (AgentStatus::Inactive === $this->status) {
            $this->status = AgentStatus::Approved;
        }
    }

    /**
     * @phpstan-impure
     *
     * @return bool whether it became inactive (approved and silent for 7 days)
     */
    public function markInactiveIfSilent(\DateTimeImmutable $now): bool
    {
        if (AgentStatus::Approved !== $this->status || $this->lastSeenAt > $now->modify(self::INACTIVE_AFTER)) {
            return false;
        }

        $this->status = AgentStatus::Inactive;

        return true;
    }

    public function appliedConfigVersion(): int
    {
        return $this->appliedConfigVersion;
    }

    public function desiredConfig(): ?AgentConfig
    {
        return null === $this->desiredConfig ? null : AgentConfig::fromPayload($this->desiredConfig);
    }

    /**
     * The next SET_CONFIG: a new desired configuration with the next version.
     */
    public function configure(AgentConfig $config): AgentConfig
    {
        $versioned = $config->withVersion(max($this->desiredConfigVersion, $this->appliedConfigVersion) + 1);
        $this->desiredConfigVersion = $versioned->version;
        $this->desiredConfig = $versioned->toPayload();

        return $versioned;
    }

    /**
     * The agent reports an older configuration than the desired one (lost order, restored volume).
     */
    public function lagsBehindConfig(): bool
    {
        return null !== $this->desiredConfig && $this->appliedConfigVersion < $this->desiredConfigVersion;
    }
}
