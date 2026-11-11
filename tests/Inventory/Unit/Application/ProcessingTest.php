<?php

declare(strict_types=1);

namespace Tests\Inventory\Unit\Application;

use Inventory\Application\Command\Inventory\ProcessInventory;
use Inventory\Application\EventHandler\QueueInventoryProcessing;
use Inventory\Application\Protocol\CycloneDxReader;
use Inventory\Domain\Event\InventoryCompleted;
use Inventory\Domain\Event\InventoryProcessed;
use Inventory\Domain\Model\AppInstance;
use Inventory\Domain\Model\Assignment;
use Inventory\Domain\Model\Digest;
use Inventory\Domain\Model\InventoryTrigger;
use Inventory\Domain\Model\Manifest;
use Inventory\Domain\Model\MediaType;
use Inventory\Domain\Model\RunStatus;
use Inventory\Domain\Model\SnapshotRef;
use Inventory\Domain\Model\SnapshotSubject;
use Inventory\Domain\Model\Subject;
use Inventory\Domain\Model\SubjectKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Audit\Target;
use Symfony\Component\Uid\Uuid;
use Tests\Inventory\Support\Documents;
use Tests\Inventory\Support\Inv;
use Tests\Inventory\Support\InventoryWorld;
use Tests\Organization\Support\RecordingCommands;

use function Symfony\Component\String\b;

final class ProcessingTest extends TestCase
{
    private const string SECOND_RUN = '019a6b31-5e00-7f12-a3b4-c5d6e7f80913';
    private const string OTHER_AGENT = '019a6b2c-8f00-7a11-8b22-3c44d55e6f02';

    #[Test]
    public function it_should_turn_an_inventory_into_host_facts_applications_images_and_packages(): void
    {
        $world = new InventoryWorld();
        $facts = Documents::hostFacts();
        $app = Documents::sbom('compose:shop/php', ['pkg:composer/symfony/http-kernel@6.4.3', 'pkg:npm/%40hotwired/turbo@8.0.23']);
        $image = Documents::sbom('image:'.Inv::IMAGE, ['pkg:deb/debian/openssl@3.0.15-1']);
        $os = Documents::sbom('host', ['pkg:deb/debian/bash@5.2']);
        [$manifest, $documents] = $this->manifest($facts, [
            [$this->subject('compose:shop/php', SubjectKind::ComposeService, $app, ['compose_project' => 'shop', 'service' => 'php', 'image_id' => Inv::IMAGE]), $app],
            [$this->subject('image:'.Inv::IMAGE, SubjectKind::ContainerImage, $image, ['image_id' => Inv::IMAGE, 'tags' => ['shop-php:1']]), $image],
            [$this->subject('host', SubjectKind::Host, $os), $os],
        ]);
        $run = $world->completed($manifest, $documents);
        $world->process()(new ProcessInventory(Inv::RUN));

        self::assertSame(RunStatus::Processed, $run->status());
        self::assertEquals([new InventoryProcessed(Inv::RUN, Inv::TEAM)], $run->releaseEvents());
        $host = $world->hosts->find(Uuid::fromString(Inv::HOST));
        self::assertNotNull($host);
        self::assertSame(['hostname', 'os', 'kernel', 'arch', 'runtimes', 'docker', 'agent'], array_keys($host->facts()));
        self::assertSame([Documents::digest($facts), Documents::digest($os), Inv::RUN], [$host->factsDigest()?->value, $host->packagesDigest()?->value, $host->lastInventoryId()?->toRfc4122()]);

        $apps = array_values($world->apps->apps);
        self::assertCount(1, $apps);
        self::assertSame(['compose:shop/php', Inv::IMAGE, Assignment::Auto, Inv::ENVIRONMENT], [$apps[0]->subjectKey(), $apps[0]->imageId(), $apps[0]->assignment(), $apps[0]->environmentId()?->toRfc4122()], 'The single application of the host goes to the default environment of the token.');
        $images = array_values($world->images->images);
        self::assertSame([Inv::IMAGE, ['shop-php:1']], [$images[0]->imageId(), $images[0]->fields()['tags'] ?? null]);

        self::assertCount(3, $world->packages->packages, 'One package list per SBOM.');
        $purls = array_merge(...array_map(static fn (array $list): array => array_map(static fn ($p): string => $p->purl->value, $list), array_values($world->packages->packages)));
        sort($purls);
        self::assertSame(['pkg:composer/symfony/http-kernel@6.4.3', 'pkg:deb/debian/bash@5.2', 'pkg:deb/debian/openssl@3.0.15-1', 'pkg:npm/%40hotwired/turbo@8.0.23'], $purls);
        self::assertSame(
            [SnapshotSubject::HostPackages, SnapshotSubject::HostFacts, SnapshotSubject::Application, SnapshotSubject::Image],
            array_map(static fn ($r): SnapshotSubject => $r->subjectType(), $world->records->records),
        );

        $world->process()(new ProcessInventory(Inv::RUN));
        self::assertCount(4, $world->records->records, 'Processed twice: nothing more.');

        $world->clock->modify('+1 day');
        $world->completed($manifest, [], self::SECOND_RUN);
        $world->process()(new ProcessInventory(self::SECOND_RUN));
        self::assertCount(1, $world->images->images, 'The same image, followed.');
        self::assertEquals($world->clock->now(), $images[0]->lastSeenAt());
    }

    #[Test]
    public function it_should_follow_applications_and_mark_gone_those_the_same_agent_no_longer_reports(): void
    {
        $world = new InventoryWorld();
        $world->environments = [];
        $facts = Documents::hostFacts();
        $a = Documents::sbom('path:/a', ['pkg:composer/a/a@1']);
        $b = Documents::sbom('path:/b', ['pkg:composer/b/b@1']);
        [$first, $documents] = $this->manifest($facts, [[$this->subject('path:/a', SubjectKind::Path, $a, ['path' => '/a']), $a], [$this->subject('path:/b', SubjectKind::Path, $b, ['path' => '/b']), $b]]);
        $world->completed($first, $documents);
        $world->process()(new ProcessInventory(Inv::RUN));
        $other = AppInstance::discover(Uuid::v7(), Uuid::fromString(Inv::TEAM), Uuid::fromString(Inv::HOST), Uuid::fromString(self::OTHER_AGENT), Inv::compose(), Inv::now());
        $world->apps->add($other);

        $world->clock->modify('+6 hours');
        [$second, $documents] = $this->manifest($facts, [[$this->subject('path:/a', SubjectKind::Path, $a, ['path' => '/a', 'origin' => 'declared']), $a]]);
        $world->completed($second, $documents, self::SECOND_RUN);
        $world->process()(new ProcessInventory(self::SECOND_RUN));

        $byKey = [];
        foreach ($world->apps->apps as $app) {
            $byKey[$app->subjectKey()] = $app;
        }
        self::assertCount(3, $byKey, 'Followed, never duplicated.');
        self::assertTrue($byKey['path:/a']->isPresent());
        self::assertSame('declared', $byKey['path:/a']->fields()['origin'] ?? null);
        self::assertEquals($world->clock->now(), $byKey['path:/a']->lastSeenAt());
        self::assertFalse($byKey['path:/b']->isPresent(), 'No longer reported by its agent.');
        self::assertTrue($byKey['compose:shop/php']->isPresent(), 'Another agent of the host: not its call.');
        self::assertCount(2, $world->packages->packages, 'Unchanged SBOM: its packages are not extracted again.');
    }

    #[Test]
    public function it_should_attach_by_label_first_and_never_over_a_human(): void
    {
        $world = new InventoryWorld();
        $world->environments = [Inv::ENVIRONMENT, '019a6b2c-4f00-7a11-8b22-3c44d55e6f02'];
        $facts = Documents::hostFacts();
        $labeled = Documents::sbom('compose:shop/php', []);
        $plain = Documents::sbom('compose:shop/worker', []);
        $foreign = Documents::sbom('compose:shop/cron', []);
        [$manifest, $documents] = $this->manifest($facts, [
            [$this->subject('compose:shop/php', SubjectKind::ComposeService, $labeled, ['compose_project' => 'shop', 'labels' => ['environment' => '019a6b2c-4f00-7a11-8b22-3c44d55e6f02']]), $labeled],
            [$this->subject('compose:shop/worker', SubjectKind::ComposeService, $plain, ['compose_project' => 'shop']), $plain],
            [$this->subject('compose:shop/cron', SubjectKind::ComposeService, $foreign, ['compose_project' => 'shop', 'labels' => ['environment' => '019a6b2c-4f00-7a11-8b22-3c44d55e6f99']]), $foreign],
        ]);
        $world->completed($manifest, $documents);
        $world->process()(new ProcessInventory(Inv::RUN));

        $environments = [];
        foreach ($world->apps->apps as $app) {
            $environments[$app->subjectKey()] = $app->environmentId()?->toRfc4122();
        }
        self::assertSame(['compose:shop/php' => '019a6b2c-4f00-7a11-8b22-3c44d55e6f02', 'compose:shop/worker' => null, 'compose:shop/cron' => null], $environments, 'Several applications: the default environment is not guessed; a label of another team is ignored.');

        $human = array_find($world->apps->apps, static fn (AppInstance $a): bool => 'compose:shop/php' === $a->subjectKey());
        self::assertNotNull($human);
        $human->unassign();
        $world->completed($manifest, [], self::SECOND_RUN);
        $world->process()(new ProcessInventory(self::SECOND_RUN));
        self::assertNull($human->environmentId(), 'Detached by a human: the label does not attach it again.');
    }

    #[Test]
    public function it_should_skip_what_cannot_be_read_and_attach_nothing_without_agent(): void
    {
        $world = new InventoryWorld();
        $facts = Documents::hostFacts();
        $broken = Documents::sbom('path:/a', []);
        [$manifest] = $this->manifest($facts, [[$this->subject('path:/a', SubjectKind::Path, $broken, ['path' => '/a']), $broken]]);
        $world->completed($manifest, [Documents::digest($facts) => '{"format_version":2}', Documents::digest($broken) => 'not json'], agentId: self::OTHER_AGENT);
        $world->process()(new ProcessInventory(Inv::RUN));

        self::assertSame([], $world->hosts->hosts, 'Unreadable facts: the host keeps its previous state.');
        $world->completed($manifest, [], '019a6b31-5e00-7f12-a3b4-c5d6e7f80915', self::OTHER_AGENT);
        $world->blobs->blobs = [];
        $world->process()(new ProcessInventory('019a6b31-5e00-7f12-a3b4-c5d6e7f80915'));
        self::assertSame([], $world->hosts->hosts, 'Snapshot gone from the store: skipped too.');
        self::assertSame([], $world->packages->packages);
        $apps = array_values($world->apps->apps);
        self::assertCount(1, $apps, 'The application is still followed.');
        self::assertNull($apps[0]->environmentId(), 'Its agent is gone: no default environment.');
        self::assertSame(RunStatus::Processed, $world->runs->find(Uuid::fromString(Inv::RUN))?->status());
    }

    #[Test]
    public function it_should_ignore_an_inventory_unknown_or_not_completed(): void
    {
        $world = new InventoryWorld();
        $world->process()(new ProcessInventory('nope'));
        $world->process()(new ProcessInventory(Inv::RUN));
        $open = Inv::run();
        $world->runs->add($open);
        $world->process()(new ProcessInventory(Inv::RUN));
        self::assertSame(RunStatus::Open, $open->status());
        self::assertSame([], $world->records->records);

        $world->agent(environment: '019a6b2c-4f00-7a11-8b22-3c44d55e6f99');
        $facts = Documents::hostFacts();
        $single = Documents::sbom('path:/a', []);
        [$manifest, $documents] = $this->manifest($facts, [[$this->subject('path:/a', SubjectKind::Path, $single, ['path' => '/a']), $single]]);
        $world->completed($manifest, $documents, self::SECOND_RUN);
        $world->process()(new ProcessInventory(self::SECOND_RUN));
        self::assertNull(array_first($world->apps->apps)?->environmentId(), 'A default environment that is not the team\'s is ignored.');

        $command = new ProcessInventory(Inv::RUN);
        self::assertSame(['inventory.process', []], [$command->auditAction()->name, $command->auditContext()->values]);
        self::assertEquals(Target::of('inventory', Inv::RUN), $command->auditTarget());
    }

    #[Test]
    public function it_should_queue_the_processing_of_a_completed_inventory(): void
    {
        $commands = new RecordingCommands();
        new QueueInventoryProcessing($commands)(new InventoryCompleted(Inv::RUN, Inv::TEAM));
        self::assertEquals([new ProcessInventory(Inv::RUN)], $commands->dispatched);
    }

    #[Test]
    public function it_should_read_the_packages_of_an_sbom(): void
    {
        $result = CycloneDxReader::packages([
            'components' => [
                ['purl' => 'pkg:composer/a/b@1', 'properties' => [['name' => 'depverify:dependency', 'value' => 'transitive'], ['name' => 'depverify:source', 'value' => 'vendor/composer/installed.json'], ['name' => 'depverify:package-manager', 'value' => 'composer'], ['name' => 'other', 'value' => 'x'], 'junk', ['name' => 1]],
                    'components' => [['purl' => 'pkg:npm/c@2']]],
                ['name' => 'no purl'],
                ['purl' => 'not a purl'],
                ['purl' => 3],
                'junk',
            ],
        ]);
        self::assertSame(['pkg:composer/a/b@1', 'pkg:npm/c@2'], array_map(static fn ($p): string => $p->purl->value, $result['packages']));
        self::assertSame(['transitive', 'vendor/composer/installed.json', 'composer'], [$result['packages'][0]->dependency, $result['packages'][0]->source, $result['packages'][0]->manager]);
        self::assertSame([null, null, null], [$result['packages'][1]->dependency, $result['packages'][1]->source, $result['packages'][1]->manager]);
        self::assertSame(4, $result['skipped']);
        self::assertSame(['packages' => [], 'skipped' => 0], CycloneDxReader::packages([]));
        self::assertSame(['packages' => [], 'skipped' => 0], CycloneDxReader::packages(['components' => 'x']));
    }

    #[Test]
    public function it_should_stop_reading_a_huge_sbom(): void
    {
        $components = array_fill(0, CycloneDxReader::MAX_COMPONENTS + 5, ['purl' => 'pkg:npm/a@1']);
        $result = CycloneDxReader::packages(['components' => $components]);
        self::assertCount(CycloneDxReader::MAX_COMPONENTS, $result['packages']);
        self::assertSame(5, $result['skipped']);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function subject(string $key, SubjectKind $kind, string $document, array $fields = []): Subject
    {
        return new Subject($key, $kind, new SnapshotRef(Digest::of(Documents::digest($document)), b($document)->length(), MediaType::CycloneDx), $fields);
    }

    /**
     * @param list<array{Subject, string}> $subjects
     *
     * @return array{Manifest, array<string, string>}
     */
    private function manifest(string $facts, array $subjects): array
    {
        $documents = [Documents::digest($facts) => $facts];
        foreach ($subjects as [$subject, $document]) {
            $documents[$subject->sbom->digest->value] = $document;
        }

        return [new Manifest(InventoryTrigger::Scheduled, Inv::now(), Inv::now(), new SnapshotRef(Digest::of(Documents::digest($facts)), b($facts)->length(), MediaType::HostFacts), array_map(static fn (array $s): Subject => $s[0], $subjects)), $documents];
    }
}
