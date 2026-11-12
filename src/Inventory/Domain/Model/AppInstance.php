<?php

declare(strict_types=1);

namespace Inventory\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Inventory\Domain\Event\ApplicationPlaced;
use Shared\Domain\Model\EventRecording;
use Shared\Domain\Model\RecordsEvents;
use Symfony\Component\Uid\Uuid;

/**
 * An application found on a host (cadrage §5): a path, a Compose service or a standalone container, identified by
 * (host, subject_key). It belongs to an environment of a project of the host's team once attached: automatically by
 * the rules of the processing, or by a human, whose choice the rules never override. The key (up to 4 200 characters)
 * is unique per host through its SHA-256, a B-tree entry being smaller. A host may carry several agents (one per
 * Compose project): an application is `gone` only when the agent that reported it no longer does. An application missing from
 * the last inventory of its host is `gone`, with its history.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inventory_app_instance')]
#[ORM\UniqueConstraint(name: 'inventory_app_instance_subject', columns: ['host_id', 'subject_key_hash'])]
#[ORM\Index(name: 'inventory_app_instance_team', columns: ['team_id'])]
#[ORM\Index(name: 'inventory_app_instance_environment', columns: ['environment_id'])]
class AppInstance implements RecordsEvents
{
    use EventRecording;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $environmentId = null;

    #[ORM\Column(length: 16, enumType: Assignment::class)]
    private Assignment $assignment = Assignment::None;

    #[ORM\Column]
    private bool $present = true;

    /**
     * @param array<string, mixed> $fields the subject's fields (path, runtime, service, containers…)
     */
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\Column(type: 'uuid')]
        private Uuid $teamId,
        #[ORM\Column(type: 'uuid')]
        private Uuid $hostId,
        #[ORM\Column(type: 'uuid')]
        private Uuid $agentId,
        #[ORM\Column(type: 'text')]
        private string $subjectKey,
        #[ORM\Column(length: 64)]
        private string $subjectKeyHash,
        #[ORM\Column(length: 32, enumType: SubjectKind::class)]
        private SubjectKind $kind,
        #[ORM\Column(type: 'json')]
        private array $fields,
        #[ORM\Column(length: 71)]
        private string $sbomDigest,
        #[ORM\Column(length: 71, nullable: true)]
        private ?string $imageId,
        #[ORM\Column]
        private \DateTimeImmutable $firstSeenAt,
        #[ORM\Column]
        private \DateTimeImmutable $lastSeenAt,
    ) {
    }

    public static function discover(Uuid $id, Uuid $teamId, Uuid $hostId, Uuid $agentId, Subject $subject, \DateTimeImmutable $at): self
    {
        if (!$subject->kind->isApplication()) {
            throw new InvalidInventory('Not an application subject.');
        }

        return new self($id, $teamId, $hostId, $agentId, $subject->key, self::hashOf($subject->key), $subject->kind, $subject->fields, $subject->sbom->digest->value, $subject->string('image_id'), $at, $at);
    }

    public static function hashOf(string $subjectKey): string
    {
        return hash('sha256', $subjectKey);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function teamId(): Uuid
    {
        return $this->teamId;
    }

    public function hostId(): Uuid
    {
        return $this->hostId;
    }

    public function agentId(): Uuid
    {
        return $this->agentId;
    }

    public function subjectKey(): string
    {
        return $this->subjectKey;
    }

    public function kind(): SubjectKind
    {
        return $this->kind;
    }

    /**
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    public function sbomDigest(): Digest
    {
        return Digest::of($this->sbomDigest);
    }

    public function imageId(): ?string
    {
        return $this->imageId;
    }

    public function environmentId(): ?Uuid
    {
        return $this->environmentId;
    }

    public function assignment(): Assignment
    {
        return $this->assignment;
    }

    public function isPresent(): bool
    {
        return $this->present;
    }

    public function firstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function lastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    /**
     * In the last inventory of its host again: its fields and SBOM as reported now.
     */
    public function seen(Subject $subject, Uuid $agentId, \DateTimeImmutable $at): void
    {
        $this->agentId = $agentId;
        $this->fields = $subject->fields;
        $this->sbomDigest = $subject->sbom->digest->value;
        $this->imageId = $subject->string('image_id');
        $this->present = true;
        $this->lastSeenAt = $at;
    }

    public function isReportedBy(Uuid $agentId): bool
    {
        return $this->agentId->equals($agentId);
    }

    public function markGone(): void
    {
        $this->present = false;
    }

    /**
     * The automatic rules attach an application nobody attached nor detached by hand.
     *
     * @phpstan-impure
     */
    public function autoAssign(Uuid $environmentId): bool
    {
        if (Assignment::None !== $this->assignment) {
            return false;
        }

        $this->environmentId = $environmentId;
        $this->assignment = Assignment::Auto;

        return true;
    }

    /**
     * A human attaches it (the caller checked that the environment belongs to the host's team).
     */
    public function assign(Uuid $environmentId): void
    {
        $this->environmentId = $environmentId;
        $this->assignment = Assignment::Manual;
        $this->recordThat(new ApplicationPlaced($this->id->toRfc4122(), $this->teamId->toRfc4122()));
    }

    /**
     * A human detaches it: the automatic rules will not attach it again.
     */
    public function unassign(): void
    {
        $this->environmentId = null;
        $this->assignment = Assignment::Manual;
        $this->recordThat(new ApplicationPlaced($this->id->toRfc4122(), $this->teamId->toRfc4122()));
    }
}
