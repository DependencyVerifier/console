<?php

declare(strict_types=1);

namespace Tests\Inventory\Unit\Domain;

use Inventory\Domain\Model\Digest;
use Inventory\Domain\Model\DigestMismatch;
use Inventory\Domain\Model\EnvironmentOfAnotherTeam;
use Inventory\Domain\Model\InvalidInventory;
use Inventory\Domain\Model\InventoryNotOpen;
use Inventory\Domain\Model\InventoryTrigger;
use Inventory\Domain\Model\Manifest;
use Inventory\Domain\Model\MediaType;
use Inventory\Domain\Model\ScopeViolation;
use Inventory\Domain\Model\SnapshotRef;
use Inventory\Domain\Model\SnapshotsMissing;
use Inventory\Domain\Model\SnapshotTooLarge;
use Inventory\Domain\Model\Subject;
use Inventory\Domain\Model\SubjectKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Inventory\Support\Inv;

use function Symfony\Component\String\u;

final class ManifestTest extends TestCase
{
    #[Test]
    public function it_should_list_each_digest_once_and_find_its_reference(): void
    {
        $manifest = Inv::manifest([Inv::compose(), Inv::image(), Inv::compose('shop', 'worker', sbom: 1)]);
        self::assertSame([Inv::digest(9)->value, Inv::digest(1)->value, Inv::digest(2)->value], $manifest->digests());
        self::assertSame($manifest->hostFacts, $manifest->refOf(Inv::digest(9)));
        self::assertSame([Inv::digest(9)->value, Inv::digest(1)->value, Inv::digest(2)->value], Inv::manifest([Inv::compose(), Inv::compose('shop', 'worker', sbom: 1), Inv::image()])->digests(), 'A list, even when a duplicate is in the middle.');
        self::assertSame(1002, $manifest->refOf(Inv::digest(2))?->size);
        self::assertNull($manifest->refOf(Inv::digest(7)));
        self::assertEquals($manifest, Manifest::fromArray($manifest->toArray()), 'Stored as JSON and read back.');
        self::assertSame(['trigger' => 'scan_now', 'started_at' => '2026-11-09T08:01:10+00:00'], array_intersect_key($manifest->toArray(), ['trigger' => 1, 'started_at' => 1]));
    }

    #[Test]
    public function it_should_keep_a_compose_agent_inside_its_project(): void
    {
        $manifest = Inv::manifest([Inv::compose(), Inv::image(), Inv::compose('blog', 'php', 5), Inv::path(), Inv::host(),
            new Subject('container:solo', SubjectKind::Container, Inv::sbom(6), ['container_name' => 'solo'])]);
        self::assertSame(['compose:blog/php', 'path:/opt/legacy-app', 'host', 'container:solo'], $manifest->outsideComposeProject('shop'));
        self::assertSame([], Inv::manifest([Inv::compose(), Inv::image()])->outsideComposeProject('shop'));
        self::assertSame(['compose:shop/php'], Inv::manifest([Inv::compose()])->outsideComposeProject('blog'));
    }

    /**
     * @return iterable<string, array{\Closure(): mixed}>
     */
    public static function invalidManifests(): iterable
    {
        yield 'facts with an SBOM media type' => [static fn () => new Manifest(InventoryTrigger::Startup, Inv::now(), Inv::now(), Inv::sbom(1), [])];
        yield 'ends before it starts' => [static fn () => new Manifest(InventoryTrigger::Startup, Inv::now(), Inv::now()->modify('-1 second'), new SnapshotRef(Inv::digest(9), 1, MediaType::HostFacts), [])];
        yield 'duplicate subject' => [static fn () => Inv::manifest([Inv::compose(), Inv::compose(sbom: 3)])];
        yield 'too many subjects' => [static fn () => Inv::manifest(array_map(static fn (int $i): Subject => Inv::path('/app'.$i, $i), range(1, 1001)))];
        yield 'digest in upper case' => [static fn () => Digest::of('sha256:'.u('A')->repeat(64)->toString())];
        yield 'digest of another algorithm' => [static fn () => Digest::of('md5:'.u('a')->repeat(32)->toString())];
        yield 'short digest' => [static fn () => Digest::of('sha256:abc')];
        yield 'digest with trailing data' => [static fn () => Digest::of('sha256:'.u('a')->repeat(64)->toString().' ')];
        yield 'negative size' => [static fn () => new SnapshotRef(Inv::digest(1), -1, MediaType::CycloneDx)];
        yield 'size beyond 200 MB' => [static fn () => new SnapshotRef(Inv::digest(1), 209715201, MediaType::CycloneDx)];
        yield 'host key on a path' => [static fn () => new Subject('host', SubjectKind::Path, Inv::sbom(1))];
        yield 'host under another key' => [static fn () => new Subject('host:web', SubjectKind::Host, Inv::sbom(1))];
        yield 'empty path key' => [static fn () => new Subject('path:', SubjectKind::Path, Inv::sbom(1))];
        yield 'image key on a compose service' => [static fn () => new Subject('image:sha256:x', SubjectKind::ComposeService, Inv::sbom(1))];
        yield 'key too long' => [static fn () => new Subject('path:/'.u('a')->repeat(4195)->toString(), SubjectKind::Path, Inv::sbom(1))];
        yield 'subject SBOM with the facts media type' => [static fn () => new Subject('path:/a', SubjectKind::Path, new SnapshotRef(Inv::digest(1), 1, MediaType::HostFacts))];
    }

    /**
     * @param \Closure(): mixed $build
     */
    #[Test]
    #[DataProvider('invalidManifests')]
    public function it_should_refuse_a_manifest_out_of_the_protocol(\Closure $build): void
    {
        $this->expectException(InvalidInventory::class);
        $build();
    }

    #[Test]
    public function it_should_name_the_refused_subject_briefly(): void
    {
        $key = 'path:/'.u('a')->repeat(200)->toString();
        try {
            new Subject($key, SubjectKind::Container, Inv::sbom(1));
            self::fail('A path key on a container.');
        } catch (InvalidInventory $refused) {
            self::assertSame(\sprintf('Invalid subject "%s".', u($key)->truncate(80)->toString()), $refused->getMessage());
        }
    }

    #[Test]
    public function it_should_accept_the_bounds(): void
    {
        self::assertCount(1000, Inv::manifest(array_map(static fn (int $i): Subject => Inv::path('/app'.$i, $i), range(1, 1000)))->subjects);
        self::assertSame(209715200, new SnapshotRef(Inv::digest(1), 209715200, MediaType::CycloneDx)->size);
        self::assertSame(0, new SnapshotRef(Inv::digest(1), 0, MediaType::CycloneDx)->size);
        self::assertSame('path:/'.u('a')->repeat(4194)->toString(), new Subject('path:/'.u('a')->repeat(4194)->toString(), SubjectKind::Path, Inv::sbom(1))->key);
        self::assertSame('path:/', new Subject('path:/', SubjectKind::Path, Inv::sbom(1))->key);
        self::assertEquals(Manifest::class, Inv::manifest()::class);
        $same = new Manifest(InventoryTrigger::Startup, Inv::now(), Inv::now(), new SnapshotRef(Inv::digest(9), 1, MediaType::HostFacts), []);
        self::assertSame([Inv::digest(9)->value], $same->digests(), 'A collection of one second.');
    }

    #[Test]
    public function it_should_read_the_fields_of_a_subject(): void
    {
        $subject = Inv::compose(environment: Inv::ENVIRONMENT);
        self::assertSame(['shop', null, null, [Inv::IMAGE], [], Inv::ENVIRONMENT], [
            $subject->string('compose_project'), $subject->string('missing'), $subject->string('container_ids'),
            [$subject->string('image_id')], $subject->strings('tags'), $subject->environmentLabel(),
        ]);
        self::assertSame([u('9')->repeat(64)->toString()], $subject->strings('container_ids'));
        self::assertSame(['environment' => Inv::ENVIRONMENT, 'name' => 'Shop'], $subject->map('labels'));
        self::assertSame([], $subject->map('service'), 'Not a map.');
        self::assertNull(Inv::compose()->environmentLabel());
        $odd = new Subject('compose:a/b', SubjectKind::ComposeService, Inv::sbom(1), ['service' => '', 'tags' => 'x', 'labels' => ['environment' => 3], 'mixed' => ['a', 1, 'b']]);
        self::assertSame([null, [], null, ['a', 'b']], [$odd->string('service'), $odd->strings('tags'), $odd->environmentLabel(), $odd->strings('mixed')]);
        self::assertNull(new Subject('compose:a/b', SubjectKind::ComposeService, Inv::sbom(1), ['labels' => ['environment' => '']])->environmentLabel());
        self::assertEquals($subject, Subject::fromArray($subject->toArray()));
    }

    #[Test]
    public function it_should_name_its_kinds(): void
    {
        self::assertSame([true, true, true, false, false], array_map(static fn (SubjectKind $k): bool => $k->isApplication(), SubjectKind::cases()));
        self::assertSame(['path:', 'compose:', 'container:', 'image:', 'host'], array_map(static fn (SubjectKind $k): string => $k->keyPrefix(), SubjectKind::cases()));
        self::assertTrue(Inv::digest(1)->equals(Digest::of(Inv::digest(1)->value)));
        self::assertFalse(Inv::digest(1)->equals(Inv::digest(2)));
        self::assertSame('sha256:'.hash('sha256', 'x'), Digest::ofContent('x')->value);
    }

    #[Test]
    public function it_should_name_every_rule(): void
    {
        $rules = [
            'invalid_payload' => new InvalidInventory(), 'scope_violation' => new ScopeViolation(), 'digest_mismatch' => new DigestMismatch(),
            'payload_too_large' => new SnapshotTooLarge(), 'inventory_not_open' => new InventoryNotOpen(),
            'environment_of_another_team' => new EnvironmentOfAnotherTeam(), 'missing_snapshots' => new SnapshotsMissing(['d']),
        ];
        foreach ($rules as $key => $rule) {
            self::assertSame($key, $rule->ruleKey());
        }

        self::assertSame('Too big.', new SnapshotTooLarge('Too big.')->getMessage());
        self::assertSame(['d'], new SnapshotsMissing(['d'])->digests);
    }
}
