<?php

declare(strict_types=1);

namespace Inventory\Domain\Model;

/**
 * Step 1 of an inventory (protocol §8.2): what the agent collected, announced by digests. At most 1 000 subjects,
 * one key each, one host subject at most.
 */
final readonly class Manifest
{
    public const int MAX_SUBJECTS = 1000;

    /**
     * @param list<Subject> $subjects
     */
    public function __construct(
        public InventoryTrigger $trigger,
        public \DateTimeImmutable $startedAt,
        public \DateTimeImmutable $completedAt,
        public SnapshotRef $hostFacts,
        public array $subjects,
    ) {
        if (MediaType::HostFacts !== $hostFacts->mediaType || \count($subjects) > self::MAX_SUBJECTS || $completedAt < $startedAt) {
            throw new InvalidInventory('Invalid manifest.');
        }

        $keys = array_map(static fn (Subject $subject): string => $subject->key, $subjects);
        if (\count($keys) !== \count(array_unique($keys))) {
            throw new InvalidInventory('Duplicate subject_key.');
        }
    }

    /**
     * Every digest the inventory needs, each once.
     *
     * @return list<string>
     */
    public function digests(): array
    {
        $digests = [$this->hostFacts->digest->value];
        foreach ($this->subjects as $subject) {
            $digests[] = $subject->sbom->digest->value;
        }

        return array_values(array_unique($digests));
    }

    public function refOf(Digest $digest): ?SnapshotRef
    {
        if ($this->hostFacts->digest->equals($digest)) {
            return $this->hostFacts;
        }

        foreach ($this->subjects as $subject) {
            if ($subject->sbom->digest->equals($digest)) {
                return $subject->sbom;
            }
        }

        return null;
    }

    /**
     * What a compose / labeled agent may not send (protocol §8.4): only the services of its own Compose project and
     * the images they run are in its scope; host paths, standalone containers and the host itself are not.
     *
     * @return list<string> the keys outside the scope
     */
    public function outsideComposeProject(string $project): array
    {
        $outside = [];
        foreach ($this->subjects as $subject) {
            $inScope = SubjectKind::ContainerImage === $subject->kind
                || (SubjectKind::ComposeService === $subject->kind && $subject->string('compose_project') === $project);
            if (!$inScope) {
                $outside[] = $subject->key;
            }
        }

        return $outside;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'trigger' => $this->trigger->value,
            'started_at' => $this->startedAt->format(\DATE_ATOM),
            'completed_at' => $this->completedAt->format(\DATE_ATOM),
            'host_facts' => $this->hostFacts->toArray(),
            'subjects' => array_map(static fn (Subject $subject): array => $subject->toArray(), $this->subjects),
        ];
    }

    /**
     * @param array<string, mixed> $data what toArray() stored
     */
    public static function fromArray(array $data): self
    {
        /** @var array{trigger: string, started_at: string, completed_at: string, host_facts: array{digest: string, size: int, media_type: string}, subjects: list<array{subject_key: string, kind: string, sbom: array{digest: string, size: int, media_type: string}, fields: array<string, mixed>}>} $data */
        return new self(
            InventoryTrigger::from($data['trigger']),
            new \DateTimeImmutable($data['started_at']),
            new \DateTimeImmutable($data['completed_at']),
            SnapshotRef::fromArray($data['host_facts']),
            array_map(Subject::fromArray(...), $data['subjects']),
        );
    }
}
