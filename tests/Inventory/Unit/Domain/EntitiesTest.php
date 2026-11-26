<?php

declare(strict_types=1);

namespace Tests\Inventory\Unit\Domain;

use Inventory\Domain\Event\HostFactsRecorded;
use Inventory\Domain\Event\InventoryCompleted;
use Inventory\Domain\Event\InventoryProcessed;
use Inventory\Domain\Model\AppInstance;
use Inventory\Domain\Model\Assignment;
use Inventory\Domain\Model\ContainerImage;
use Inventory\Domain\Model\DocumentationLink;
use Inventory\Domain\Model\HostInventory;
use Inventory\Domain\Model\InvalidDocumentationLink;
use Inventory\Domain\Model\InvalidInventory;
use Inventory\Domain\Model\InventoryNotOpen;
use Inventory\Domain\Model\InventoryRun;
use Inventory\Domain\Model\MediaType;
use Inventory\Domain\Model\ObservedHostFacts;
use Inventory\Domain\Model\RunStatus;
use Inventory\Domain\Model\ScopeViolation;
use Inventory\Domain\Model\SnapshotBlob;
use Inventory\Domain\Model\SnapshotRecord;
use Inventory\Domain\Model\SnapshotsMissing;
use Inventory\Domain\Model\SnapshotSubject;
use Inventory\Domain\Model\Subject;
use Inventory\Domain\Model\SubjectKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Tests\Inventory\Support\Inv;

use function Symfony\Component\String\u;

final class EntitiesTest extends TestCase
{
    #[Test]
    public function it_should_complete_an_inventory_once_every_snapshot_is_stored(): void
    {
        $run = Inv::run();
        self::assertSame([Inv::RUN, Inv::AGENT, Inv::TEAM, Inv::HOST, RunStatus::Open], [$run->id()->toRfc4122(), $run->agentId()->toRfc4122(), $run->teamId()->toRfc4122(), $run->hostId()->toRfc4122(), $run->status()]);
        self::assertEquals(Inv::manifest([Inv::compose(), Inv::image()]), $run->manifest());
        self::assertEquals(Inv::now(), $run->receivedAt());
        self::assertTrue($run->isFrom(Uuid::fromString(Inv::AGENT)));
        self::assertFalse($run->isFrom(Uuid::fromString(Inv::HOST)));
        self::assertSame([Inv::digest(9)->value, Inv::digest(2)->value], $run->missingAmong([Inv::digest(1)->value]));
        self::assertSame([], $run->missingAmong([Inv::digest(9)->value, Inv::digest(1)->value, Inv::digest(2)->value, Inv::digest(7)->value]));

        try {
            $run->complete([Inv::digest(1)->value], Inv::now());
            self::fail('Missing snapshots.');
        } catch (SnapshotsMissing $missing) {
            self::assertSame([Inv::digest(9)->value, Inv::digest(2)->value], $missing->digests);
        }

        self::assertNull($run->completedAt());
        $later = Inv::now()->modify('+1 minute');
        self::assertTrue($run->complete($run->manifest()->digests(), $later));
        self::assertSame(RunStatus::Completed, $run->status());
        self::assertEquals($later, $run->completedAt());
        self::assertEquals([new InventoryCompleted(Inv::RUN, Inv::TEAM)], $run->releaseEvents());
        self::assertFalse($run->complete([], $later), 'Completed again: nothing changes.');
        self::assertSame([], $run->releaseEvents());
        $this->expectException(InventoryNotOpen::class);
        $run->assertOpen();
    }

    #[Test]
    public function it_should_be_processed_once_completed_only(): void
    {
        $run = Inv::run();
        self::assertFalse($run->markProcessed(Inv::now()));
        $run->complete($run->manifest()->digests(), Inv::now());
        self::assertTrue($run->markProcessed(Inv::now()->modify('+5 seconds')));
        self::assertSame(RunStatus::Processed, $run->status());
        self::assertEquals([new InventoryCompleted(Inv::RUN, Inv::TEAM), new InventoryProcessed(Inv::RUN, Inv::TEAM)], $run->releaseEvents());
        self::assertEquals(Inv::now()->modify('+5 seconds'), $run->processedAt());
        self::assertFalse($run->markProcessed(Inv::now()));
        self::assertFalse($run->complete([], Inv::now()), 'Processed: completing changes nothing.');
        self::assertNull(Inv::run()->processedAt());
    }

    #[Test]
    public function it_should_discard_an_inventory_left_open_an_hour(): void
    {
        $run = Inv::run();
        self::assertFalse($run->discardIfStale(Inv::now()->modify('+59 minutes 59 seconds')));
        self::assertTrue($run->discardIfStale(Inv::now()->modify('+1 hour')));
        self::assertSame(RunStatus::Discarded, $run->status());
        self::assertFalse($run->discardIfStale(Inv::now()->modify('+2 hours')));
        $this->expectException(InventoryNotOpen::class);
        $run->complete($run->manifest()->digests(), Inv::now());
    }

    #[Test]
    public function it_should_keep_a_rejected_inventory_rejected(): void
    {
        $run = InventoryRun::reject(Uuid::fromString(Inv::RUN), Uuid::fromString(Inv::AGENT), Uuid::fromString(Inv::TEAM), Uuid::fromString(Inv::HOST), Inv::manifest(), Inv::now());
        self::assertSame(RunStatus::Rejected, $run->status());
        self::assertFalse($run->discardIfStale(Inv::now()->modify('+2 hours')));
        try {
            $run->complete($run->manifest()->digests(), Inv::now());
            self::fail('Rejected.');
        } catch (ScopeViolation $violation) {
            self::assertSame('scope_violation', $violation->ruleKey());
        }

        $completed = Inv::run();
        $completed->complete($completed->manifest()->digests(), Inv::now());
        self::assertFalse($completed->discardIfStale(Inv::now()->modify('+2 hours')));
    }

    #[Test]
    public function it_should_attach_an_application_automatically_until_a_human_decides(): void
    {
        $app = AppInstance::discover(Uuid::fromString(Inv::APP), Uuid::fromString(Inv::TEAM), Uuid::fromString(Inv::HOST), Uuid::fromString(Inv::AGENT), Inv::compose(), Inv::now());
        self::assertSame([Inv::APP, Inv::TEAM, Inv::HOST, 'compose:shop/php', SubjectKind::ComposeService, Inv::IMAGE, Assignment::None, true], [
            $app->id()->toRfc4122(), $app->teamId()->toRfc4122(), $app->hostId()->toRfc4122(), $app->subjectKey(), $app->kind(), $app->imageId(), $app->assignment(), $app->isPresent(),
        ]);
        self::assertSame(Inv::compose()->fields, $app->fields());
        self::assertSame(Inv::AGENT, $app->agentId()->toRfc4122());
        self::assertEquals(Inv::digest(1), $app->sbomDigest());
        self::assertEquals([Inv::now(), Inv::now()], [$app->firstSeenAt(), $app->lastSeenAt()]);
        self::assertNull($app->environmentId());
        self::assertSame(hash('sha256', 'compose:shop/php'), AppInstance::hashOf('compose:shop/php'));

        $environment = Uuid::fromString(Inv::ENVIRONMENT);
        self::assertTrue($app->autoAssign($environment));
        self::assertSame([Assignment::Auto, Inv::ENVIRONMENT], [$app->assignment(), $app->environmentId()?->toRfc4122()]);
        self::assertFalse($app->autoAssign(Uuid::fromString(Inv::TEAM)), 'Already attached.');
        self::assertSame(Inv::ENVIRONMENT, $app->environmentId()?->toRfc4122());

        $app->unassign();
        self::assertSame([Assignment::Manual, null], [$app->assignment(), $app->environmentId()]);
        self::assertFalse($app->autoAssign($environment), 'Detached by a human: never attached again by the rules.');
        $app->assign($environment);
        self::assertSame([Assignment::Manual, Inv::ENVIRONMENT], [$app->assignment(), $app->environmentId()?->toRfc4122()]);
    }

    #[Test]
    public function it_should_follow_an_application_across_inventories(): void
    {
        $app = AppInstance::discover(Uuid::fromString(Inv::APP), Uuid::fromString(Inv::TEAM), Uuid::fromString(Inv::HOST), Uuid::fromString(Inv::AGENT), Inv::path(), Inv::now());
        self::assertNull($app->imageId());
        $app->markGone();
        self::assertFalse($app->isPresent());

        $later = Inv::now()->modify('+6 hours');
        $app->seen(Inv::path(sbom: 5), Uuid::fromString(Inv::AGENT), $later);
        self::assertSame([true, Inv::digest(5)->value], [$app->isPresent(), $app->sbomDigest()->value]);
        self::assertEquals([Inv::now(), $later], [$app->firstSeenAt(), $app->lastSeenAt()]);
        $app->seen(Inv::compose(), Uuid::fromString('019a6b2c-8f00-7a11-8b22-3c44d55e6f02'), $later);
        self::assertTrue($app->isReportedBy(Uuid::fromString('019a6b2c-8f00-7a11-8b22-3c44d55e6f02')));
        self::assertFalse($app->isReportedBy(Uuid::fromString(Inv::AGENT)));
        self::assertSame(Inv::IMAGE, $app->imageId());
        self::assertSame('shop', $app->fields()['compose_project'] ?? null);

        $this->expectException(InvalidInventory::class);
        AppInstance::discover(Uuid::v7(), Uuid::fromString(Inv::TEAM), Uuid::fromString(Inv::HOST), Uuid::fromString(Inv::AGENT), Inv::image(), Inv::now());
    }

    #[Test]
    public function it_should_record_a_container_image_once_per_image_id(): void
    {
        $id = Uuid::v7();
        $image = ContainerImage::discover($id, Uuid::fromString(Inv::TEAM), Inv::image(), Inv::now());
        self::assertSame([$id, Inv::TEAM, Inv::IMAGE, ['shop-php:latest']], [$image->id(), $image->teamId()->toRfc4122(), $image->imageId(), $image->fields()['tags'] ?? null]);
        self::assertEquals(Inv::digest(2), $image->sbomDigest());
        self::assertEquals([Inv::now(), Inv::now()], [$image->firstSeenAt(), $image->lastSeenAt()]);
        $image->seen(Inv::image(8), Inv::now()->modify('+1 day'));
        self::assertSame(Inv::digest(8)->value, $image->sbomDigest()->value);
        self::assertEquals(Inv::now()->modify('+1 day'), $image->lastSeenAt());

        foreach ([Inv::compose(), new Subject('image:x', SubjectKind::ContainerImage, Inv::sbom(1))] as $notAnImage) {
            try {
                ContainerImage::discover(Uuid::v7(), Uuid::fromString(Inv::TEAM), $notAnImage, Inv::now());
                self::fail('Not an image.');
            } catch (InvalidInventory) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function it_should_keep_the_facts_and_packages_of_a_host(): void
    {
        $host = HostInventory::of(Uuid::fromString(Inv::HOST), Uuid::fromString(Inv::TEAM));
        self::assertSame([Inv::HOST, Inv::TEAM, [], null, null, null, null], [$host->hostId()->toRfc4122(), $host->teamId()->toRfc4122(), $host->facts(), $host->factsDigest(), $host->packagesDigest(), $host->lastInventoryId(), $host->inventoriedAt()]);

        $host->record(Uuid::fromString(Inv::RUN), Uuid::fromString(Inv::AGENT), ['kernel' => '6.1'], Inv::digest(9), Inv::digest(4), Inv::now());
        self::assertSame([['kernel' => '6.1'], Inv::digest(9)->value, Inv::digest(4)->value, Inv::RUN], [$host->facts(), $host->factsDigest()?->value, $host->packagesDigest()?->value, $host->lastInventoryId()?->toRfc4122()]);
        self::assertEquals(Inv::now(), $host->inventoriedAt());
        $host->record(Uuid::fromString(Inv::RUN), Uuid::fromString(Inv::AGENT), [], Inv::digest(10), null, Inv::now());
        self::assertSame(Inv::digest(4)->value, $host->packagesDigest()?->value, 'An inventory without host subject keeps the last OS packages.');
        $events = $host->releaseEvents();
        self::assertEquals([
            new HostFactsRecorded(Inv::HOST, Inv::TEAM, Inv::AGENT, new ObservedHostFacts(kernel: '6.1')),
            new HostFactsRecorded(Inv::HOST, Inv::TEAM, Inv::AGENT, new ObservedHostFacts()),
        ], $events, 'At each inventory, changed or not, by the agent of the run.');
    }

    #[Test]
    public function it_should_read_the_observed_facts_of_a_host_document(): void
    {
        self::assertEquals(
            new ObservedHostFacts('YggWeb', 'debian', '13', 'Debian GNU/Linux 13 (trixie)', '6.17.13-2-pve', 'x86_64', 'engine-1'),
            ObservedHostFacts::fromDocument(['hostname' => 'YggWeb', 'os' => ['id' => 'debian', 'version_id' => '13', 'pretty_name' => 'Debian GNU/Linux 13 (trixie)'], 'kernel' => '6.17.13-2-pve', 'arch' => 'x86_64', 'docker' => ['engine_id' => 'engine-1', 'engine_version' => '29.1'], 'runtimes' => []]),
        );
        self::assertEquals(new ObservedHostFacts(), ObservedHostFacts::fromDocument([]), 'Nothing observed.');
        self::assertEquals(
            new ObservedHostFacts(),
            ObservedHostFacts::fromDocument(['hostname' => '', 'os' => 'debian', 'kernel' => 6, 'arch' => ['x86_64'], 'docker' => null]),
            'Empty, not a string, not an object: not observed.',
        );
        self::assertEquals(
            new ObservedHostFacts(osId: 'debian', dockerEngineId: 'engine-1'),
            ObservedHostFacts::fromDocument(['os' => ['id' => 'debian', 'version_id' => 13, 'pretty_name' => ''], 'docker' => ['engine_id' => 'engine-1']]),
        );
    }

    #[Test]
    public function it_should_store_a_blob_and_a_history_entry_as_given(): void
    {
        $id = Uuid::v7();
        $blob = SnapshotBlob::store($id, Uuid::fromString(Inv::TEAM), Inv::digest(1), MediaType::CycloneDx, 1200, 'gzip-bytes', Inv::now());
        self::assertSame([$id, Inv::TEAM, Inv::digest(1)->value, MediaType::CycloneDx, 1200, 'gzip-bytes'], [$blob->id(), $blob->teamId()->toRfc4122(), $blob->digest()->value, $blob->mediaType(), $blob->size(), $blob->gzip()]);
        self::assertEquals(Inv::now(), $blob->storedAt());
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, 'from-the-database');
        rewind($stream);
        $loaded = SnapshotBlob::store($id, Uuid::fromString(Inv::TEAM), Inv::digest(1), MediaType::CycloneDx, 1, '', Inv::now());
        (fn () => $this->content = $stream)->call($loaded);
        self::assertSame('from-the-database', $loaded->gzip(), 'Doctrine loads a bytea as a stream.');
        self::assertSame('from-the-database', $loaded->gzip());

        $record = SnapshotRecord::take($id, Uuid::fromString(Inv::TEAM), Uuid::fromString(Inv::RUN), SnapshotSubject::Application, Uuid::fromString(Inv::APP), Inv::digest(1), Inv::now());
        self::assertSame([$id, Inv::TEAM, Inv::RUN, SnapshotSubject::Application, Inv::APP, Inv::digest(1)->value], [$record->id(), $record->teamId()->toRfc4122(), $record->inventoryId()->toRfc4122(), $record->subjectType(), $record->subjectId()->toRfc4122(), $record->digest()->value]);
        self::assertEquals(Inv::now(), $record->takenAt());
    }

    #[Test]
    public function it_should_keep_the_documentation_links_a_human_gives_an_application(): void
    {
        $app = AppInstance::discover(Uuid::fromString(Inv::APP), Uuid::fromString(Inv::TEAM), Uuid::fromString(Inv::HOST), Uuid::fromString(Inv::AGENT), Inv::compose(), Inv::now());
        self::assertSame([], $app->links());

        $app->document([new DocumentationLink(' https://wiki.example.com/shop ', '  Runbook '), new DocumentationLink('http://grafana.local:3000/d/x?y=1#z'), new DocumentationLink('https://wiki.example.com/shop', 'Again')]);
        self::assertEquals([new DocumentationLink('https://wiki.example.com/shop', 'Runbook'), new DocumentationLink('http://grafana.local:3000/d/x?y=1#z')], $app->links(), 'Trimmed, each URL once, the first label kept.');
        self::assertSame(['url' => 'http://grafana.local:3000/d/x?y=1#z', 'label' => null], $app->links()[1]->toArray());
        self::assertNull(new DocumentationLink('https://a.example', ' ')->label);

        $ten = array_map(static fn (int $i): DocumentationLink => new DocumentationLink('https://example.com/'.$i), range(1, 10));
        $app->document([...$ten, $ten[0]]);
        self::assertCount(10, $app->links(), 'Ten at most, counted once each.');
        $before = $app->links();
        try {
            $app->document([...$ten, new DocumentationLink('https://example.com/11')]);
            self::fail('Eleven links.');
        } catch (InvalidDocumentationLink $refused) {
            self::assertSame('invalid_documentation_link', $refused->ruleKey());
        }
        self::assertEquals($before, $app->links(), 'Refused: unchanged.');
        $app->document([]);
        self::assertSame([], $app->links());

        self::assertSame(DocumentationLink::MAX_URL, u(new DocumentationLink('https://e.com/'.u('a')->repeat(2048 - 14))->url)->length());
        self::assertSame(u('a')->repeat(100)->toString(), new DocumentationLink('https://e.com', u('a')->repeat(100)->toString())->label);
        foreach ([
            ['https://e.com/'.u('a')->repeat(2048 - 13), null], ['ftp://e.com', null], ['javascript:alert(1)', null], ['//e.com', null], ['e.com', null],
            ['https://user:secret@e.com', null], ['https://user@e.com', null], ['https://user@e.com/x', null], ['https://', null], ['https:///path', null], ['https://e.com/a b', null], ["https://e.com/\u{0007}", null],
            ['https://e.com', u('a')->repeat(101)->toString()], ['https://e.com', "Run\u{0000}book"],
        ] as [$url, $label]) {
            try {
                new DocumentationLink($url, $label);
                self::fail($url);
            } catch (InvalidDocumentationLink) {
            }
        }
        self::assertSame('HTTPS://E.COM', new DocumentationLink('HTTPS://E.COM')->url);
        foreach (['https://e.com:8443', 'https://e.com?q=1', 'https://e.com#top', 'https://e.com/a@b', 'https://[2001:db8::1]/x'] as $valid) {
            self::assertSame($valid, new DocumentationLink($valid)->url);
        }
    }
}
