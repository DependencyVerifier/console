<?php

declare(strict_types=1);

namespace Exposure\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Exposure\Domain\Event\FindingFixed;
use Exposure\Domain\Event\FindingOpened;
use Shared\Domain\Model\EventRecording;
use Shared\Domain\Model\RecordsEvents;
use Symfony\Component\Uid\Uuid;

/**
 * A risk of an application or a host: a vulnerable version, an end of life, an outdated direct dependency. Its
 * identity is (subject, kind, key); it is open while the matching produces it, fixed when it no longer does, and
 * open again if it comes back (first detection kept).
 */
#[ORM\Entity]
#[ORM\Table(name: 'exposure_finding')]
#[ORM\UniqueConstraint(name: 'exposure_finding_identity', columns: ['team_id', 'subject_type', 'subject_id', 'kind', 'finding_key'])]
#[ORM\Index(name: 'exposure_finding_team_status', columns: ['team_id', 'status'])]
class Finding implements RecordsEvents
{
    use EventRecording;

    /**
     * @param list<string> $aliases
     */
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        private Uuid $id,
        #[ORM\Column(type: 'uuid')]
        private Uuid $teamId,
        #[ORM\Column(length: 16, enumType: SubjectType::class)]
        private SubjectType $subjectType,
        #[ORM\Column(type: 'uuid')]
        private Uuid $subjectId,
        #[ORM\Column(length: 16, enumType: FindingKind::class)]
        private FindingKind $kind,
        #[ORM\Column(name: 'finding_key', type: 'text')]
        private string $key,
        #[ORM\Column(type: 'text')]
        private string $title,
        #[ORM\Column(type: 'text')]
        private string $package,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $installed,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $fixed,
        #[ORM\Column(length: 16, nullable: true)]
        private ?string $severity,
        #[ORM\Column(nullable: true)]
        private ?float $cvss,
        #[ORM\Column]
        private bool $knownExploited,
        #[ORM\Column(nullable: true)]
        private ?float $epss,
        #[ORM\Column(length: 16, nullable: true)]
        private ?string $eolLevel,
        #[ORM\Column(type: 'date_immutable', nullable: true)]
        private ?\DateTimeImmutable $endOfLife,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $latest,
        #[ORM\Column(type: 'json')]
        private array $aliases,
        #[ORM\Column(length: 16, enumType: FindingStatus::class)]
        private FindingStatus $status,
        #[ORM\Column]
        private \DateTimeImmutable $firstSeenAt,
        #[ORM\Column]
        private \DateTimeImmutable $lastSeenAt,
        #[ORM\Column(nullable: true)]
        private ?\DateTimeImmutable $fixedAt,
    ) {
    }

    public static function detect(Uuid $id, Uuid $teamId, SubjectType $subjectType, Uuid $subjectId, FindingFacts $facts, \DateTimeImmutable $now): self
    {
        $finding = new self($id, $teamId, $subjectType, $subjectId, $facts->kind, $facts->key, '', '', null, null, null, null, false, null, null, null, null, [], FindingStatus::Open, $now, $now, null);
        $finding->apply($facts, $now);
        $finding->recordThat(new FindingOpened($id->toRfc4122(), $teamId->toRfc4122(), false));

        return $finding;
    }

    /**
     * Produced again: its facts are refreshed; a fixed finding opens again.
     */
    public function observe(FindingFacts $facts, \DateTimeImmutable $now): void
    {
        $this->apply($facts, $now);
        if (FindingStatus::Fixed === $this->status) {
            $this->status = FindingStatus::Open;
            $this->fixedAt = null;
            $this->recordThat(new FindingOpened($this->id->toRfc4122(), $this->teamId->toRfc4122(), true));
        }
    }

    /**
     * No longer produced.
     */
    public function resolve(\DateTimeImmutable $now): void
    {
        if (FindingStatus::Open === $this->status) {
            $this->status = FindingStatus::Fixed;
            $this->fixedAt = $now;
            $this->recordThat(new FindingFixed($this->id->toRfc4122(), $this->teamId->toRfc4122()));
        }
    }

    /**
     * Its identity within its team.
     */
    public function identity(): string
    {
        return self::identityOf($this->subjectType, $this->subjectId->toRfc4122(), $this->kind, $this->key);
    }

    public static function identityOf(SubjectType $subjectType, string $subjectId, FindingKind $kind, string $key): string
    {
        return $subjectType->value.' '.$subjectId.' '.$kind->value.' '.$key;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function teamId(): Uuid
    {
        return $this->teamId;
    }

    public function subjectType(): SubjectType
    {
        return $this->subjectType;
    }

    public function subjectId(): Uuid
    {
        return $this->subjectId;
    }

    public function kind(): FindingKind
    {
        return $this->kind;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function facts(): FindingFacts
    {
        return new FindingFacts($this->kind, $this->key, $this->title, $this->package, $this->installed, $this->fixed, $this->severity, $this->cvss, $this->knownExploited, $this->epss, $this->eolLevel, $this->endOfLife, $this->latest, $this->aliases);
    }

    public function status(): FindingStatus
    {
        return $this->status;
    }

    public function firstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function lastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function fixedAt(): ?\DateTimeImmutable
    {
        return $this->fixedAt;
    }

    private function apply(FindingFacts $facts, \DateTimeImmutable $now): void
    {
        $this->title = $facts->title;
        $this->package = $facts->package;
        $this->installed = $facts->installed;
        $this->fixed = $facts->fixed;
        $this->severity = $facts->severity;
        $this->cvss = $facts->cvss;
        $this->knownExploited = $facts->knownExploited;
        $this->epss = $facts->epss;
        $this->eolLevel = $facts->eolLevel;
        $this->endOfLife = $facts->endOfLife;
        $this->latest = $facts->latest;
        $this->aliases = $facts->aliases;
        $this->lastSeenAt = $now;
    }
}
