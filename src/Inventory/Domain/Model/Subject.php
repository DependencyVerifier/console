<?php

declare(strict_types=1);

namespace Inventory\Domain\Model;

use function Symfony\Component\String\u;

/**
 * One subject of a manifest (protocol §8.2): its key, its kind, its SBOM and the fields of its kind, kept as received
 * (bounded strings and lists only, read by the accessors).
 */
final readonly class Subject
{
    public const int MAX_KEY = 4200;

    /**
     * @param array<string, mixed> $fields the fields of the kind (path, origin, runtime, compose_project…)
     */
    public function __construct(public string $key, public SubjectKind $kind, public SnapshotRef $sbom, public array $fields = [])
    {
        $prefix = $kind->keyPrefix();
        $keyMatches = SubjectKind::Host === $kind ? 'host' === $key : u($key)->startsWith($prefix) && u($key)->length() > u($prefix)->length();
        if (!$keyMatches || u($key)->length() > self::MAX_KEY || MediaType::CycloneDx !== $sbom->mediaType) {
            throw new InvalidInventory(\sprintf('Invalid subject "%s".', u($key)->truncate(80)));
        }
    }

    public function string(string $field): ?string
    {
        $value = $this->fields[$field] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * @return list<string>
     */
    public function strings(string $field): array
    {
        $values = $this->fields[$field] ?? [];

        return \is_array($values) ? array_values(array_filter($values, \is_string(...))) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function map(string $field): array
    {
        $value = $this->fields[$field] ?? [];
        if (!\is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * The `dev.depverify.environment` label of a Compose service: an environment id chosen by the operator.
     */
    public function environmentLabel(): ?string
    {
        $environment = $this->map('labels')['environment'] ?? null;

        return \is_string($environment) && '' !== $environment ? $environment : null;
    }

    /**
     * @return array{subject_key: string, kind: string, sbom: array{digest: string, size: int, media_type: string}, fields: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['subject_key' => $this->key, 'kind' => $this->kind->value, 'sbom' => $this->sbom->toArray(), 'fields' => $this->fields];
    }

    /**
     * @param array{subject_key: string, kind: string, sbom: array{digest: string, size: int, media_type: string}, fields: array<string, mixed>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['subject_key'], SubjectKind::from($data['kind']), SnapshotRef::fromArray($data['sbom']), $data['fields']);
    }
}
