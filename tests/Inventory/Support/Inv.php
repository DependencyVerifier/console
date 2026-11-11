<?php

declare(strict_types=1);

namespace Tests\Inventory\Support;

use Inventory\Domain\Model\Digest;
use Inventory\Domain\Model\InventoryRun;
use Inventory\Domain\Model\InventoryTrigger;
use Inventory\Domain\Model\Manifest;
use Inventory\Domain\Model\MediaType;
use Inventory\Domain\Model\SnapshotRef;
use Inventory\Domain\Model\Subject;
use Inventory\Domain\Model\SubjectKind;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\String\u;

/**
 * Fixed ids and builders of the Inventory tests (the team, host and agent ids are Fleet's test ones).
 */
final class Inv
{
    public const string TEAM = '019a6b2c-2f00-7a11-8b22-3c44d55e6f01';
    public const string OTHER_TEAM = '019a6b2c-2f00-7a11-8b22-3c44d55e6f02';
    public const string HOST = '019a6b2c-7f00-7a11-8b22-3c44d55e6f01';
    public const string AGENT = '019a6b2c-8f00-7a11-8b22-3c44d55e6f01';
    public const string ENVIRONMENT = '019a6b2c-4f00-7a11-8b22-3c44d55e6f01';
    public const string RUN = '019a6b31-5e00-7f12-a3b4-c5d6e7f80912';
    public const string APP = '019a6b31-6e00-7f12-a3b4-c5d6e7f80901';
    public const string IMAGE = 'sha256:a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-09 08:02:00');
    }

    public static function digest(int $n): Digest
    {
        return Digest::of('sha256:'.u((string) $n)->padStart(64, '0')->toString());
    }

    public static function sbom(int $n): SnapshotRef
    {
        return new SnapshotRef(self::digest($n), 1000 + $n, MediaType::CycloneDx);
    }

    public static function compose(string $project = 'shop', string $service = 'php', int $sbom = 1, ?string $environment = null): Subject
    {
        return new Subject('compose:'.$project.'/'.$service, SubjectKind::ComposeService, self::sbom($sbom), [
            'compose_project' => $project, 'service' => $service, 'container_ids' => [u('9')->repeat(64)->toString()], 'image_id' => self::IMAGE,
            'labels' => null === $environment ? [] : ['environment' => $environment, 'name' => 'Shop'],
        ]);
    }

    public static function image(int $sbom = 2): Subject
    {
        return new Subject('image:'.self::IMAGE, SubjectKind::ContainerImage, self::sbom($sbom), ['image_id' => self::IMAGE, 'repo_digests' => [], 'tags' => ['shop-php:latest'], 'provenance' => 'local-build']);
    }

    public static function path(string $path = '/opt/legacy-app', int $sbom = 3): Subject
    {
        return new Subject('path:'.$path, SubjectKind::Path, self::sbom($sbom), ['path' => $path, 'origin' => 'declared', 'runtime' => ['name' => 'php', 'version' => '8.2.26']]);
    }

    public static function host(int $sbom = 4): Subject
    {
        return new Subject('host', SubjectKind::Host, self::sbom($sbom));
    }

    /**
     * @param list<Subject> $subjects
     */
    public static function manifest(array $subjects = [], int $facts = 9): Manifest
    {
        return new Manifest(InventoryTrigger::ScanNow, self::now()->modify('-50 seconds'), self::now()->modify('-8 seconds'), new SnapshotRef(self::digest($facts), 1874, MediaType::HostFacts), $subjects);
    }

    public static function run(?Manifest $manifest = null, string $id = self::RUN, string $teamId = self::TEAM): InventoryRun
    {
        return InventoryRun::open(Uuid::fromString($id), Uuid::fromString(self::AGENT), Uuid::fromString($teamId), Uuid::fromString(self::HOST), $manifest ?? self::manifest([self::compose(), self::image()]), self::now());
    }
}
