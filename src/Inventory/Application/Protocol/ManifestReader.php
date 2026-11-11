<?php

declare(strict_types=1);

namespace Inventory\Application\Protocol;

use Inventory\Domain\Model\Digest;
use Inventory\Domain\Model\InventoryTrigger;
use Inventory\Domain\Model\Manifest;
use Inventory\Domain\Model\MediaType;
use Inventory\Domain\Model\SnapshotRef;
use Inventory\Domain\Model\Subject;
use Inventory\Domain\Model\SubjectKind;
use Shared\Application\Protocol\AgentPayload;
use Shared\Application\Protocol\PayloadRefused;

use function Symfony\Component\String\u;

/**
 * Reads the manifest of protocol §8.2 field by field (unknown fields ignored). The fields of each kind are kept as
 * bounded strings and lists: what the processing and the views read, never more.
 */
final class ManifestReader
{
    private const int MAX_TEXT = 1024;
    private const int MAX_LIST = 100;

    /** @var array<string, list<string>> the string fields of each kind */
    private const array STRINGS = [
        'path' => ['path', 'origin'],
        'compose_service' => ['compose_project', 'service', 'image_id'],
        'container' => ['container_name', 'container_id', 'image_id'],
        'container_image' => ['image_id', 'provenance', 'base_image'],
        'host' => [],
    ];

    /** @var array<string, list<string>> the list fields of each kind */
    private const array LISTS = [
        'path' => [],
        'compose_service' => ['container_ids'],
        'container' => [],
        'container_image' => ['repo_digests', 'tags'],
        'host' => [],
    ];

    public static function inventoryId(AgentPayload $payload): string
    {
        $id = $payload->string('inventory_id');
        if ([] === u($id)->match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')) {
            throw PayloadRefused::invalid('inventory_id is not a UUID v7.');
        }

        return $id;
    }

    public static function read(AgentPayload $payload): Manifest
    {
        $trigger = InventoryTrigger::tryFrom($payload->string('trigger')) ?? throw PayloadRefused::invalid('Unknown trigger.');
        $subjects = [];
        foreach ($payload->objects('subjects') as $subject) {
            $subjects[] = self::subject($subject);
        }

        return new Manifest($trigger, self::time($payload, 'started_at'), self::time($payload, 'completed_at'), self::ref($payload->object('host_facts')), $subjects);
    }

    private static function subject(AgentPayload $subject): Subject
    {
        $kind = SubjectKind::tryFrom($subject->string('kind')) ?? throw PayloadRefused::invalid('Unknown subject kind.');
        $fields = [];
        foreach (self::STRINGS[$kind->value] as $name) {
            $value = $subject->optionalString($name);
            if (null !== $value) {
                $fields[$name] = u($value)->truncate(self::MAX_TEXT)->toString();
            }
        }

        foreach (self::LISTS[$kind->value] as $name) {
            $fields[$name] = self::strings($subject->listOrNull($name));
        }

        if (SubjectKind::Path === $kind && null !== ($runtime = $subject->optionalObject('runtime'))) {
            $fields['runtime'] = array_filter(['name' => $runtime->optionalString('name'), 'version' => $runtime->optionalString('version'), 'service' => $runtime->optionalString('service')], static fn (?string $v): bool => null !== $v);
        }

        if (SubjectKind::ComposeService === $kind && null !== ($labels = $subject->optionalObject('labels'))) {
            $fields['labels'] = array_filter(['name' => $labels->optionalString('name'), 'environment' => $labels->optionalString('environment'), 'paths' => self::strings($labels->listOrNull('paths')) ?: null], static fn (mixed $v): bool => null !== $v);
        }

        return new Subject($subject->string('subject_key'), $kind, self::ref($subject->object('sbom')), $fields);
    }

    private static function ref(AgentPayload $ref): SnapshotRef
    {
        $mediaType = MediaType::tryFrom($ref->string('media_type')) ?? throw PayloadRefused::invalid('Unknown media type.');

        return new SnapshotRef(Digest::of($ref->string('digest')), $ref->int('size'), $mediaType);
    }

    private static function time(AgentPayload $payload, string $field): \DateTimeImmutable
    {
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $payload->string($field), new \DateTimeZone('UTC'));

        return false === $time ? throw PayloadRefused::invalid(\sprintf('%s is not an RFC 3339 UTC time.', $field)) : $time;
    }

    /**
     * @param list<mixed>|null $values
     *
     * @return list<string>
     */
    private static function strings(?array $values): array
    {
        $strings = [];
        foreach (\array_slice($values ?? [], 0, self::MAX_LIST) as $value) {
            if (\is_string($value)) {
                $strings[] = u($value)->truncate(self::MAX_TEXT)->toString();
            }
        }

        return $strings;
    }
}
