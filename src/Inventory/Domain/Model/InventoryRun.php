<?php

declare(strict_types=1);

namespace Inventory\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Inventory\Domain\Event\InventoryCompleted;
use Shared\Domain\Model\EventRecording;
use Shared\Domain\Model\RecordsEvents;
use Symfony\Component\Uid\Uuid;

/**
 * One collection run of an agent (protocol §8): its manifest, then its snapshots, then `complete`. The id is the
 * agent's `inventory_id`: retrying the same manifest is idempotent, the same id from another agent is refused.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inventory_run')]
#[ORM\Index(name: 'inventory_run_open', columns: ['status', 'received_at'])]
#[ORM\Index(name: 'inventory_run_host', columns: ['host_id', 'received_at'])]
class InventoryRun implements RecordsEvents
{
    use EventRecording;

    public const string OPEN_FOR = '+1 hour';

    #[ORM\Column(length: 16, enumType: RunStatus::class)]
    private RunStatus $status = RunStatus::Open;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    /**
     * @param array<string, mixed> $manifest Manifest::toArray()
     */
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\Column(type: 'uuid')]
        private Uuid $agentId,
        #[ORM\Column(type: 'uuid')]
        private Uuid $teamId,
        #[ORM\Column(type: 'uuid')]
        private Uuid $hostId,
        #[ORM\Column(type: 'json')]
        private array $manifest,
        #[ORM\Column]
        private \DateTimeImmutable $receivedAt,
    ) {
    }

    public static function open(Uuid $id, Uuid $agentId, Uuid $teamId, Uuid $hostId, Manifest $manifest, \DateTimeImmutable $now): self
    {
        return new self($id, $agentId, $teamId, $hostId, $manifest->toArray(), $now);
    }

    /**
     * A manifest outside the agent's scope is kept as rejected: the same id is never accepted later.
     */
    public static function reject(Uuid $id, Uuid $agentId, Uuid $teamId, Uuid $hostId, Manifest $manifest, \DateTimeImmutable $now): self
    {
        $run = new self($id, $agentId, $teamId, $hostId, $manifest->toArray(), $now);
        $run->status = RunStatus::Rejected;

        return $run;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function agentId(): Uuid
    {
        return $this->agentId;
    }

    public function teamId(): Uuid
    {
        return $this->teamId;
    }

    public function hostId(): Uuid
    {
        return $this->hostId;
    }

    public function manifest(): Manifest
    {
        return Manifest::fromArray($this->manifest);
    }

    public function status(): RunStatus
    {
        return $this->status;
    }

    public function receivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function completedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function processedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function isFrom(Uuid $agentId): bool
    {
        return $this->agentId->equals($agentId);
    }

    public function assertOpen(): void
    {
        if (RunStatus::Rejected === $this->status) {
            throw new ScopeViolation('This inventory was rejected.');
        }

        if (RunStatus::Open !== $this->status) {
            throw new InventoryNotOpen();
        }
    }

    /**
     * @param list<string> $stored the digests of the manifest the team already holds
     *
     * @return list<string> those still to upload
     */
    public function missingAmong(array $stored): array
    {
        return array_values(array_diff($this->manifest()->digests(), $stored));
    }

    /**
     * Every snapshot stored: queued for processing. Completing it again changes nothing.
     *
     * @param list<string> $stored the digests of the manifest the team holds
     *
     * @phpstan-impure
     *
     * @return bool whether it was completed now
     */
    public function complete(array $stored, \DateTimeImmutable $now): bool
    {
        if (RunStatus::Completed === $this->status || RunStatus::Processed === $this->status) {
            return false;
        }

        $this->assertOpen();
        $missing = $this->missingAmong($stored);
        if ([] !== $missing) {
            throw new SnapshotsMissing($missing);
        }

        $this->status = RunStatus::Completed;
        $this->completedAt = $now;
        $this->recordThat(new InventoryCompleted($this->id->toRfc4122(), $this->teamId->toRfc4122()));

        return true;
    }

    /**
     * @phpstan-impure
     */
    public function markProcessed(\DateTimeImmutable $now): bool
    {
        if (RunStatus::Completed !== $this->status) {
            return false;
        }

        $this->status = RunStatus::Processed;
        $this->processedAt = $now;

        return true;
    }

    /**
     * @phpstan-impure
     */
    public function discardIfStale(\DateTimeImmutable $now): bool
    {
        if (RunStatus::Open !== $this->status || $now < $this->receivedAt->modify(self::OPEN_FOR)) {
            return false;
        }

        $this->status = RunStatus::Discarded;

        return true;
    }
}
