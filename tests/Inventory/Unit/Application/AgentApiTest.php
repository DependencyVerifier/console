<?php

declare(strict_types=1);

namespace Tests\Inventory\Unit\Application;

use Inventory\Application\Command\Agent\CompleteInventory;
use Inventory\Application\Command\Agent\Handler\CompleteInventoryHandler;
use Inventory\Application\Command\Agent\Handler\StoreSnapshotHandler;
use Inventory\Application\Command\Agent\Handler\SubmitManifestHandler;
use Inventory\Application\Command\Agent\StoreSnapshot;
use Inventory\Application\Command\Agent\SubmitManifest;
use Inventory\Application\Query\Agent\GetMissingSnapshots;
use Inventory\Application\Query\Agent\Handler\GetMissingSnapshotsHandler;
use Inventory\Application\Query\Agent\Handler\IsSnapshotStoredHandler;
use Inventory\Application\Query\Agent\IsSnapshotStored;
use Inventory\Domain\Event\InventoryCompleted;
use Inventory\Domain\Model\Digest;
use Inventory\Domain\Model\MediaType;
use Inventory\Domain\Model\RunStatus;
use Inventory\Domain\Model\SnapshotsMissing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Audit\Target;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Domain\BusinessRuleViolated;
use Symfony\Component\Uid\Uuid;
use Tests\Inventory\Support\Documents;
use Tests\Inventory\Support\Inv;
use Tests\Inventory\Support\InventoryWorld;

use function Symfony\Component\String\b;

final class AgentApiTest extends TestCase
{
    private const string OTHER_AGENT = '019a6b2c-8f00-7a11-8b22-3c44d55e6f02';

    #[Test]
    public function it_should_take_a_manifest_store_the_missing_snapshots_and_complete(): void
    {
        $world = new InventoryWorld();
        [$facts, $sbom, $body] = $this->inventory();
        $this->submit($world, $body);

        $run = $world->runs->find(Uuid::fromString(Inv::RUN));
        self::assertNotNull($run);
        self::assertSame([RunStatus::Open, Inv::AGENT, Inv::TEAM, Inv::HOST], [$run->status(), $run->agentId()->toRfc4122(), $run->teamId()->toRfc4122(), $run->hostId()->toRfc4122()]);
        self::assertEquals($world->clock->now(), $run->receivedAt());
        self::assertSame([Documents::digest($facts), Documents::digest($sbom)], $this->missing($world));
        self::assertFalse($this->stored($world, Documents::digest($sbom)));

        $this->store($world, $sbom, MediaType::CycloneDx);
        self::assertTrue($this->stored($world, Documents::digest($sbom)));
        $blob = $world->blobs->find(Uuid::fromString(Inv::TEAM), Digest::of(Documents::digest($sbom)));
        self::assertSame([b($sbom)->length(), (string) gzencode($sbom)], [$blob?->size(), $blob?->gzip()]);
        self::assertSame([Documents::digest($facts)], $this->missing($world));
        $this->store($world, $sbom, MediaType::CycloneDx);
        self::assertCount(1, $world->blobs->blobs, 'Already stored: nothing more.');

        try {
            $this->complete($world);
            self::fail('The facts are missing.');
        } catch (SnapshotsMissing $missing) {
            self::assertSame([Documents::digest($facts)], $missing->digests);
        }

        $this->store($world, $facts, MediaType::HostFacts);
        $this->complete($world);
        self::assertSame(RunStatus::Completed, $run->status());
        self::assertEquals([new InventoryCompleted(Inv::RUN, Inv::TEAM)], $run->releaseEvents());
        self::assertSame([], $this->missing($world));
        $this->complete($world);
        self::assertSame([], $run->releaseEvents(), 'Completed again: nothing.');
        $this->assertRule('inventory_not_open', fn () => $this->store($world, $sbom, MediaType::CycloneDx));
    }

    #[Test]
    public function it_should_answer_a_retried_manifest_the_same_and_refuse_its_id_to_another_agent(): void
    {
        $world = new InventoryWorld();
        $world->agent(self::OTHER_AGENT);
        [, , $body] = $this->inventory();
        $this->submit($world, $body);
        $world->clock->modify('+1 minute');
        $this->submit($world, $body);
        self::assertCount(1, $world->runs->runs);
        self::assertEquals(new \DateTimeImmutable('2026-11-09 08:02:00', new \DateTimeZone('UTC')), $world->runs->find(Uuid::fromString(Inv::RUN))?->receivedAt(), 'A retry, not a new inventory.');
        $this->assertRule('invalid_payload', fn () => $this->submit($world, $body, self::OTHER_AGENT));
        $this->assertRule('not_found', fn () => $this->missing($world, self::OTHER_AGENT), 'Another agent never sees it.');
        $this->assertRule('not_found', fn () => $this->missing($world, Inv::AGENT, 'nope'));
        $this->assertRule('not_found', fn () => $this->missing($world, Inv::AGENT, '019a6b31-5e00-7f12-a3b4-c5d6e7f80999'));
    }

    #[Test]
    public function it_should_keep_a_compose_agent_inside_its_project(): void
    {
        $world = new InventoryWorld();
        [$facts] = $this->inventory();
        $outside = Documents::manifest(Inv::RUN, $facts, [Documents::subject('compose:blog/php', 'compose_service', '{}', ['compose_project' => 'blog', 'service' => 'php'])]);
        $this->assertRule('scope_violation', fn () => $this->submit($world, (string) json_encode($outside)));
        self::assertSame([], $world->runs->runs, 'Nothing stored.');
        $host = Documents::manifest(Inv::RUN, $facts, [Documents::subject('host', 'host', '{}')]);
        $this->assertRule('scope_violation', fn () => $this->submit($world, (string) json_encode($host)));

        $world->agent(mode: 'container', scope: 'host', project: null);
        $this->submit($world, (string) json_encode($host));
        self::assertCount(1, $world->runs->runs, 'A container agent of scope host sees the host.');
    }

    #[Test]
    public function it_should_refuse_inventories_of_a_pending_agent(): void
    {
        $world = new InventoryWorld();
        $world->agent(status: 'pending');
        [, , $body] = $this->inventory();
        $this->assertRule('agent_pending', fn () => $this->submit($world, $body));
        $this->assertRule('agent_pending', fn () => $this->complete($world));
        $this->assertRule('agent_pending', fn () => $this->missing($world));
    }

    #[Test]
    public function it_should_refuse_a_snapshot_not_announced_or_under_another_type(): void
    {
        $world = new InventoryWorld();
        [$facts, $sbom, $body] = $this->inventory();
        $this->submit($world, $body);
        $this->assertRule('invalid_payload', fn () => $this->store($world, Documents::sbom('path:/x', []), MediaType::CycloneDx), 'Not in the manifest.');
        $this->assertRule('invalid_payload', fn () => $this->store($world, $facts, MediaType::CycloneDx), 'Facts sent as an SBOM.');
        $this->assertRule('digest_mismatch', static fn () => new StoreSnapshotHandler($world->inventoryAgents(), $world->blobs, $world->clock)(new StoreSnapshot(Inv::AGENT, Inv::RUN, Documents::digest($sbom), MediaType::CycloneDx->value, (string) gzencode($sbom.' '))));
        self::assertSame([], $world->blobs->blobs);
    }

    #[Test]
    public function it_should_audit_the_manifest_and_the_completion_on_the_inventory(): void
    {
        $manifest = new SubmitManifest(Inv::AGENT, Inv::RUN, '{}');
        self::assertSame(['inventory.submit_manifest', ['agent_id' => Inv::AGENT]], [$manifest->auditAction()->name, $manifest->auditContext()->values]);
        self::assertEquals(Target::of('inventory', Inv::RUN), $manifest->auditTarget());
        $store = new StoreSnapshot(Inv::AGENT, Inv::RUN, 'sha256:x', 't', 'gz');
        self::assertSame(['inventory.store_snapshot', ['digest' => 'sha256:x']], [$store->auditAction()->name, $store->auditContext()->values]);
        self::assertEquals(Target::of('inventory', Inv::RUN), $store->auditTarget());
        $complete = new CompleteInventory(Inv::AGENT, Inv::RUN);
        self::assertSame(['inventory.complete', []], [$complete->auditAction()->name, $complete->auditContext()->values]);
        self::assertEquals(Target::of('inventory', Inv::RUN), $complete->auditTarget());
    }

    /**
     * @return array{string, string, string} host facts, one SBOM, the manifest body
     */
    private function inventory(): array
    {
        $facts = Documents::hostFacts();
        $sbom = Documents::sbom('compose:shop/php', ['pkg:composer/symfony/http-kernel@6.4.3']);
        $body = (string) json_encode(Documents::manifest(Inv::RUN, $facts, [Documents::subject('compose:shop/php', 'compose_service', $sbom, ['compose_project' => 'shop', 'service' => 'php'])]));

        return [$facts, $sbom, $body];
    }

    private function submit(InventoryWorld $world, string $body, string $agentId = Inv::AGENT): void
    {
        new SubmitManifestHandler($world->inventoryAgents(), $world->runs, $world->clock)(new SubmitManifest($agentId, Inv::RUN, $body));
    }

    private function store(InventoryWorld $world, string $document, MediaType $type): void
    {
        new StoreSnapshotHandler($world->inventoryAgents(), $world->blobs, $world->clock)(new StoreSnapshot(Inv::AGENT, Inv::RUN, Documents::digest($document), $type->value, (string) gzencode($document)));
    }

    private function complete(InventoryWorld $world): void
    {
        new CompleteInventoryHandler($world->inventoryAgents(), $world->blobs, $world->clock)(new CompleteInventory(Inv::AGENT, Inv::RUN));
    }

    /**
     * @return list<string>
     */
    private function missing(InventoryWorld $world, string $agentId = Inv::AGENT, string $inventoryId = Inv::RUN): array
    {
        return new GetMissingSnapshotsHandler($world->inventoryAgents(), $world->blobs)(new GetMissingSnapshots($agentId, $inventoryId));
    }

    private function stored(InventoryWorld $world, string $digest): bool
    {
        return new IsSnapshotStoredHandler($world->inventoryAgents(), $world->blobs)(new IsSnapshotStored(Inv::AGENT, Inv::RUN, $digest));
    }

    /**
     * @param \Closure(): mixed $gesture
     */
    private function assertRule(string $rule, \Closure $gesture, string $message = ''): void
    {
        try {
            $gesture();
            self::fail('Expected '.$rule.'. '.$message);
        } catch (ResourceNotFound) {
            self::assertSame('not_found', $rule, $message);
        } catch (BusinessRuleViolated $refused) {
            self::assertSame($rule, $refused->ruleKey(), $message.' '.$refused->getMessage());
        }
    }
}
