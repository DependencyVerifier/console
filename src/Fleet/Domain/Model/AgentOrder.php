<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\String\u;

/**
 * An order of the closed catalogue for one agent (protocol §7). Queued by a human (or by the poll for a lagging
 * configuration), signed at its first delivery (issued now, valid 15 minutes) and delivered again with the same
 * signature until a result comes or it expires; an expired order is never delivered again, it is issued anew.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fleet_agent_order')]
#[ORM\Index(name: 'fleet_agent_order_agent', columns: ['agent_id', 'status'])]
class AgentOrder
{
    public const string VALIDITY = '+15 minutes';
    /** Grace given to a late result after the expiry (clock tolerance of the agent, protocol §7.2). */
    public const string RESULT_GRACE = '+60 seconds';

    #[ORM\Column(length: 16, enumType: OrderStatus::class)]
    private OrderStatus $status = OrderStatus::Queued;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $issuedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $signature = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $reason = null;

    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\Column(type: 'uuid')]
        private Uuid $agentId,
        #[ORM\Column(type: 'uuid')]
        private Uuid $teamId,
        #[ORM\Column(length: 32, enumType: OrderType::class)]
        private OrderType $type,
        #[ORM\Column(type: 'json')]
        private array $payload,
        #[ORM\Column(type: 'uuid', nullable: true)]
        private ?Uuid $requestedBy,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public static function scanNow(AgentOrderId $id, Agent $agent, ?Uuid $requestedBy, \DateTimeImmutable $now): self
    {
        return self::queue($id, $agent, OrderType::ScanNow, [], $requestedBy, $now);
    }

    public static function setConfig(AgentOrderId $id, Agent $agent, AgentConfig $config, ?Uuid $requestedBy, \DateTimeImmutable $now): self
    {
        return self::queue($id, $agent, OrderType::SetConfig, $config->toPayload(), $requestedBy, $now);
    }

    public function id(): AgentOrderId
    {
        return AgentOrderId::of($this->id);
    }

    public function agentId(): AgentId
    {
        return AgentId::of($this->agentId);
    }

    public function teamId(): Uuid
    {
        return $this->teamId;
    }

    public function type(): OrderType
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function status(): OrderStatus
    {
        return $this->status;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function requestedBy(): ?Uuid
    {
        return $this->requestedBy;
    }

    public function issuedAt(): ?\DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function expiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function signature(): ?string
    {
        return $this->signature;
    }

    public function finishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function isSigned(): bool
    {
        return null !== $this->signature;
    }

    /**
     * Fixes the validity window of the first delivery, before signing (the window is part of the signed order).
     */
    public function issue(\DateTimeImmutable $now): void
    {
        if (OrderStatus::Queued !== $this->status || null !== $this->issuedAt) {
            throw new \LogicException('An order is issued once.');
        }

        $this->issuedAt = $now;
        $this->expiresAt = $now->modify(self::VALIDITY);
    }

    public function deliver(string $signature): void
    {
        if (OrderStatus::Queued !== $this->status || null === $this->issuedAt) {
            throw new \LogicException('Only an issued, queued order is delivered.');
        }

        $this->signature = $signature;
        $this->status = OrderStatus::Delivered;
    }

    /**
     * Delivered and not expired: sent again (same signature) until its result comes.
     */
    public function isDeliverableAt(\DateTimeImmutable $now): bool
    {
        return OrderStatus::Delivered === $this->status && null !== $this->expiresAt && $now < $this->expiresAt;
    }

    /**
     * A result reported by the agent (protocol §7.5). A result for an order already finished, or never delivered,
     * changes nothing (results are sent again until a poll succeeds).
     *
     * @phpstan-impure
     */
    public function finish(OrderStatus $status, ?string $reason, \DateTimeImmutable $finishedAt, \DateTimeImmutable $now): bool
    {
        if (OrderStatus::Delivered !== $this->status || !\in_array($status, [OrderStatus::Executed, OrderStatus::Failed, OrderStatus::Rejected], true)) {
            return false;
        }

        $this->status = $status;
        $this->reason = self::reasonCode($status, $reason);
        $this->finishedAt = min($finishedAt, $now);

        return true;
    }

    /**
     * @phpstan-impure
     */
    public function expireIfLate(\DateTimeImmutable $now): bool
    {
        if (OrderStatus::Delivered !== $this->status || null === $this->expiresAt || $now < $this->expiresAt->modify(self::RESULT_GRACE)) {
            return false;
        }

        $this->status = OrderStatus::Expired;

        return true;
    }

    public function cancel(): void
    {
        if ($this->status->isOpen()) {
            $this->status = OrderStatus::Cancelled;
        }
    }

    /**
     * A short machine code from the agent (`already_running`, `scan_error`…), never free text: anything else is
     * stored as `unspecified`. Required unless executed.
     */
    private static function reasonCode(OrderStatus $status, ?string $reason): ?string
    {
        if (null === $reason) {
            return OrderStatus::Executed === $status ? null : 'unspecified';
        }

        return [] === u($reason)->match('/^[a-z][a-z0-9_]{0,63}$/') ? 'unspecified' : $reason;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function queue(AgentOrderId $id, Agent $agent, OrderType $type, array $payload, ?Uuid $requestedBy, \DateTimeImmutable $now): self
    {
        if (!$agent->isApproved()) {
            throw new AgentNotApproved();
        }

        return new self($id->value, $agent->id()->value, $agent->teamId(), $type, $payload, $requestedBy, $now);
    }
}
