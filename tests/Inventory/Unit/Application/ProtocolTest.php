<?php

declare(strict_types=1);

namespace Tests\Inventory\Unit\Application;

use Inventory\Application\Protocol\ManifestReader;
use Inventory\Application\Protocol\SnapshotDecoder;
use Inventory\Domain\Model\Digest;
use Inventory\Domain\Model\DigestMismatch;
use Inventory\Domain\Model\InvalidInventory;
use Inventory\Domain\Model\InventoryTrigger;
use Inventory\Domain\Model\MediaType;
use Inventory\Domain\Model\SnapshotTooLarge;
use Inventory\Domain\Model\SubjectKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Protocol\AgentPayload;
use Shared\Domain\BusinessRuleViolated;
use Tests\Inventory\Support\Documents;

use function Symfony\Component\String\b;
use function Symfony\Component\String\u;

final class ProtocolTest extends TestCase
{
    #[Test]
    public function it_should_read_the_manifest_example_of_the_protocol(): void
    {
        $payload = AgentPayload::parse((string) file_get_contents(\dirname(__DIR__, 2).'/Fixtures/protocol/inventory-manifest.json'));
        self::assertSame('019a6b31-5e00-7f12-a3b4-c5d6e7f80912', ManifestReader::inventoryId($payload));
        $manifest = ManifestReader::read($payload);

        self::assertSame(InventoryTrigger::ScanNow, $manifest->trigger);
        self::assertEquals(new \DateTimeImmutable('2026-11-09 08:01:10', new \DateTimeZone('UTC')), $manifest->startedAt);
        self::assertSame(['host', 'compose:shop/php', 'image:sha256:a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90', 'path:/opt/legacy-app'], array_map(static fn ($s): string => $s->key, $manifest->subjects));
        [$host, $compose, $image, $path] = $manifest->subjects;
        self::assertSame([SubjectKind::Host, []], [$host->kind, $host->fields]);
        self::assertSame([
            'compose_project' => 'shop', 'service' => 'php', 'image_id' => 'sha256:a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90',
            'container_ids' => ['9f8e7d6c5b4a39281706f5e4d3c2b1a09f8e7d6c5b4a39281706f5e4d3c2b1a0'],
            'runtime' => ['name' => 'php', 'version' => '8.4.14'], 'requires' => ['php' => '>=8.4'],
            'labels' => ['name' => 'Shop back-office', 'environment' => '019a6a00-0000-7000-8000-000000000001', 'paths' => ['/app']],
        ], $compose->fields);
        self::assertSame(['image_id' => 'sha256:a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90', 'provenance' => 'local-build', 'base_image' => 'dunglas/frankenphp:1-php8.4', 'repo_digests' => [], 'tags' => ['shop-php:latest']], $image->fields);
        self::assertSame(['path' => '/opt/legacy-app', 'origin' => 'declared', 'runtime' => ['name' => 'php', 'version' => '8.2.26', 'service' => 'php8.2-fpm'], 'requires' => ['php' => '^8.1']], $path->fields);
        self::assertSame(482113, $compose->sbom->size);
        self::assertSame(MediaType::HostFacts, $manifest->hostFacts->mediaType);
    }

    #[Test]
    public function it_should_bound_what_it_keeps_of_a_subject(): void
    {
        $long = u('a')->repeat(2000)->toString();
        $payload = AgentPayload::parse((string) json_encode(Documents::manifest('019a6b31-5e00-7f12-a3b4-c5d6e7f80912', '{}', [
            Documents::subject('image:sha256:x', 'container_image', '{}', ['image_id' => $long, 'tags' => [...array_fill(0, 150, 't'), 3], 'repo_digests' => null]),
            Documents::subject('compose:shop/php', 'compose_service', '{}', ['compose_project' => 'shop', 'labels' => ['paths' => []], 'unknown' => 'ignored']),
            Documents::subject('path:/a', 'path', '{}', ['path' => '/a']),
            Documents::subject('path:/b', 'path', '{}', ['path' => '/b', 'runtime' => ['name' => 'node', 'version' => '22.1.0'], 'requires' => ['node' => '>=20', 'ruby' => '3']]),
            Documents::subject('container:web', 'container', '{}', ['container_name' => 'web', 'runtime' => ['name' => 'php', 'version' => $long], 'requires' => ['php' => $long]]),
            Documents::subject('image:sha256:y', 'container_image', '{}', ['image_id' => 'y', 'runtime' => ['name' => 'php', 'version' => '8.4'], 'requires' => ['php' => '^8']]),
        ])));
        [$image, $compose, $path, $node, $container, $other] = ManifestReader::read($payload)->subjects;
        self::assertSame(['name' => 'node', 'version' => '22.1.0'], $node->map('runtime'), 'No service: no empty key.');
        self::assertSame(['node' => '>=20'], $node->map('requires'), 'Known runtimes only.');
        $bounded = u('a')->repeat(1024)->toString();
        self::assertSame([$bounded, $bounded], [$container->map('runtime')['version'] ?? null, $container->map('requires')['php'] ?? null]);
        self::assertSame(['image_id' => 'y', 'repo_digests' => [], 'tags' => []], $other->fields, 'An image has no runtime.');
        self::assertSame(1024, u((string) $image->string('image_id'))->length());
        self::assertCount(100, $image->strings('tags'));
        self::assertSame([], $image->strings('repo_digests'));
        self::assertSame(['compose_project' => 'shop', 'container_ids' => [], 'labels' => []], $compose->fields, 'Unknown fields ignored, empty labels kept empty.');
        self::assertSame(['path' => '/a'], $path->fields, 'No runtime: none kept.');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidManifests(): iterable
    {
        $base = Documents::manifest('019a6b31-5e00-7f12-a3b4-c5d6e7f80912', '{}', [Documents::subject('path:/a', 'path', '{}', ['path' => '/a'])]);
        yield 'inventory_id not a UUID v7' => [['inventory_id' => '019a6b31-5e00-4f12-a3b4-c5d6e7f80912'] + $base, 'invalid_payload'];
        yield 'unknown trigger' => [['trigger' => 'cron'] + $base, 'invalid_payload'];
        yield 'local time' => [['started_at' => '2026-11-09T09:01:10+01:00'] + $base, 'invalid_payload'];
        yield 'unknown kind' => [['subjects' => [['kind' => 'vm'] + $base['subjects'][0]]] + $base, 'invalid_payload'];
        yield 'unknown media type' => [['host_facts' => ['media_type' => 'text/plain'] + $base['host_facts']] + $base, 'invalid_payload'];
        yield 'tags as a string' => [['subjects' => [Documents::subject('image:x', 'container_image', '{}', ['tags' => 'latest'])]] + $base, 'invalid_payload'];
        yield 'bad digest' => [['host_facts' => ['digest' => 'sha256:x'] + $base['host_facts']] + $base, 'invalid_payload'];
        yield 'protocol 2' => [['protocol_version' => 2] + $base, 'unsupported_protocol_version'];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    #[Test]
    #[DataProvider('invalidManifests')]
    public function it_should_refuse_a_manifest_out_of_the_protocol(array $manifest, string $rule): void
    {
        try {
            $payload = AgentPayload::parse((string) json_encode($manifest));
            ManifestReader::inventoryId($payload);
            ManifestReader::read($payload);
            self::fail('Expected '.$rule);
        } catch (BusinessRuleViolated $refused) {
            self::assertSame($rule, $refused->ruleKey(), $refused->getMessage());
        }
    }

    #[Test]
    public function it_should_check_a_snapshot_in_a_stream(): void
    {
        $sbom = Documents::sbom('path:/a', ['pkg:composer/a/b@1']);
        self::assertSame(b($sbom)->length(), SnapshotDecoder::check((string) gzencode($sbom), Digest::of(Documents::digest($sbom)), MediaType::CycloneDx));
        $facts = Documents::hostFacts();
        self::assertSame(b($facts)->length(), SnapshotDecoder::check((string) gzencode($facts), Digest::of(Documents::digest($facts)), MediaType::HostFacts));
        $big = u(' ')->repeat(200000)->toString().$sbom;
        self::assertSame(200000 + b($sbom)->length(), SnapshotDecoder::check((string) gzencode($big), Digest::of(Documents::digest($big)), MediaType::CycloneDx), 'Several chunks.');
        self::assertSame('CycloneDX', SnapshotDecoder::document((string) gzencode($sbom), MediaType::CycloneDx)['bomFormat'] ?? null);
    }

    /**
     * @return iterable<string, array{\Closure(): mixed, class-string<BusinessRuleViolated>}>
     */
    public static function invalidSnapshots(): iterable
    {
        $sbom = Documents::sbom('path:/a', []);
        $check = static fn (string $gzip, string $document, MediaType $type = MediaType::CycloneDx): \Closure => static fn () => SnapshotDecoder::check($gzip, Digest::of(Documents::digest($document)), $type);
        yield 'not gzip' => [$check($sbom, $sbom), InvalidInventory::class];
        yield 'empty' => [$check('', ''), InvalidInventory::class];
        yield 'truncated gzip' => [$check(b((string) gzencode($sbom))->slice(0, -8)->toString(), $sbom), InvalidInventory::class];
        yield 'other digest' => [static fn () => SnapshotDecoder::check((string) gzencode($sbom), Digest::ofContent('other'), MediaType::CycloneDx), DigestMismatch::class];
        yield 'not JSON' => [$check((string) gzencode('{'), '{'), InvalidInventory::class];
        yield 'facts sent as an SBOM' => [$check((string) gzencode(Documents::hostFacts()), Documents::hostFacts()), InvalidInventory::class];
        yield 'SBOM sent as facts' => [$check((string) gzencode($sbom), $sbom, MediaType::HostFacts), InvalidInventory::class];
        yield 'CycloneDX 1.5' => [$check((string) gzencode(u($sbom)->replace('"1.6"', '"1.5"')->toString()), u($sbom)->replace('"1.6"', '"1.5"')->toString()), InvalidInventory::class];
        yield 'components not a list' => [$check((string) gzencode('{"bomFormat":"CycloneDX","specVersion":"1.6","components":1}'), '{"bomFormat":"CycloneDX","specVersion":"1.6","components":1}'), InvalidInventory::class];
        yield 'beyond 20 MB compressed' => [static fn () => SnapshotDecoder::check(u('a')->repeat(20971521)->toString(), Digest::ofContent(''), MediaType::CycloneDx), SnapshotTooLarge::class];
        yield 'not readable' => [static fn () => SnapshotDecoder::document('nope', MediaType::CycloneDx), InvalidInventory::class];
    }

    /**
     * @param \Closure(): mixed                  $check
     * @param class-string<BusinessRuleViolated> $class
     */
    #[Test]
    #[DataProvider('invalidSnapshots')]
    public function it_should_refuse_a_snapshot_that_is_not_what_was_announced(\Closure $check, string $class): void
    {
        $this->expectException($class);
        $check();
    }

    #[Test]
    public function it_should_name_the_expected_format(): void
    {
        foreach ([[Documents::hostFacts(), MediaType::CycloneDx, 'CycloneDX 1.6'], [Documents::sbom('path:/a', []), MediaType::HostFacts, 'host facts']] as [$document, $type, $name]) {
            try {
                SnapshotDecoder::check((string) gzencode($document), Digest::of(Documents::digest($document)), $type);
                self::fail('Wrong format.');
            } catch (InvalidInventory $refused) {
                self::assertSame(\sprintf('The snapshot is not a %s document.', $name), $refused->getMessage());
            }
        }
    }

    #[Test]
    public function it_should_stop_a_gzip_bomb_at_200_mb(): void
    {
        $bomb = (string) gzencode(u(' ')->repeat(209715201)->toString(), 9);
        self::assertLessThan(1048576, b($bomb)->length(), 'A few hundred kilobytes…');
        $this->expectException(SnapshotTooLarge::class);
        SnapshotDecoder::check($bomb, Digest::ofContent(''), MediaType::CycloneDx);
    }

    #[Test]
    public function it_should_check_a_large_snapshot_without_parsing_it(): void
    {
        $document = u(' ')->repeat(SnapshotDecoder::MAX_PARSED + 1)->toString();
        self::assertSame(SnapshotDecoder::MAX_PARSED + 1, SnapshotDecoder::check((string) gzencode($document), Digest::of(Documents::digest($document)), MediaType::CycloneDx), 'Beyond 16 MiB, the format is the processing\'s.');
        $parsed = u(' ')->repeat(SnapshotDecoder::MAX_PARSED - 1)->toString().'1';
        $this->expectException(InvalidInventory::class);
        SnapshotDecoder::check((string) gzencode($parsed), Digest::of(Documents::digest($parsed)), MediaType::CycloneDx);
    }
}
