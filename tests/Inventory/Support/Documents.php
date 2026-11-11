<?php

declare(strict_types=1);

namespace Tests\Inventory\Support;

use function Symfony\Component\String\b;

/**
 * Snapshot documents of protocol §8.1 for the tests: a CycloneDX 1.6 SBOM (profile §8.5) and host facts, as the
 * agent produces them (deterministic, no timestamp).
 */
final class Documents
{
    /**
     * @param list<string> $purls
     */
    public static function sbom(string $subjectKey, array $purls, string $manager = 'composer'): string
    {
        return (string) json_encode([
            'bomFormat' => 'CycloneDX',
            'specVersion' => '1.6',
            'version' => 1,
            'metadata' => ['component' => ['type' => 'application', 'name' => $subjectKey, 'bom-ref' => $subjectKey]],
            'components' => array_map(static fn (string $purl): array => [
                'type' => 'library', 'bom-ref' => $purl, 'name' => $purl, 'purl' => $purl,
                'properties' => [
                    ['name' => 'depverify:dependency', 'value' => 'direct'],
                    ['name' => 'depverify:source', 'value' => 'composer.lock'],
                    ['name' => 'depverify:package-manager', 'value' => $manager],
                ],
            ], $purls),
        ], \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    public static function hostFacts(string $hostname = 'web-01'): string
    {
        return (string) json_encode([
            'format_version' => 1,
            'hostname' => $hostname,
            'os' => ['id' => 'debian', 'version_id' => '12', 'pretty_name' => 'Debian GNU/Linux 12 (bookworm)'],
            'kernel' => '6.1.0-27-amd64',
            'arch' => 'x86_64',
            'runtimes' => [['name' => 'php', 'version' => '8.2.26', 'path' => '/usr/bin/php8.2']],
            'docker' => ['engine_id' => 'b1c7a0f2-3e4d-4c5b-9a8f-7e6d5c4b3a21', 'engine_version' => '27.3.1'],
            'agent' => ['version' => '0.1.0', 'mode' => 'container', 'scope' => 'compose', 'config_version' => 0],
        ], \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    public static function digest(string $document): string
    {
        return 'sha256:'.hash('sha256', $document);
    }

    /**
     * @param array<string, mixed> $fields the subject's own fields
     *
     * @return array<string, mixed>
     */
    public static function subject(string $key, string $kind, string $document, array $fields = []): array
    {
        return ['subject_key' => $key, 'kind' => $kind] + $fields + ['sbom' => ['digest' => self::digest($document), 'size' => b($document)->length(), 'media_type' => 'application/vnd.cyclonedx+json;version=1.6']];
    }

    /**
     * @param list<array<string, mixed>> $subjects
     *
     * @return array{protocol_version: int, inventory_id: string, trigger: string, started_at: string, completed_at: string, host_facts: array{digest: string, size: int, media_type: string}, subjects: list<array<string, mixed>>}
     */
    public static function manifest(string $inventoryId, string $facts, array $subjects): array
    {
        return [
            'protocol_version' => 1,
            'inventory_id' => $inventoryId,
            'trigger' => 'scan_now',
            'started_at' => '2026-11-09T08:01:10Z',
            'completed_at' => '2026-11-09T08:01:52Z',
            'host_facts' => ['digest' => self::digest($facts), 'size' => b($facts)->length(), 'media_type' => 'application/vnd.depverify.host-facts+json;version=1'],
            'subjects' => $subjects,
        ];
    }
}
