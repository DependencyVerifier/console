<?php

declare(strict_types=1);

namespace Tests\Inventory\Functional;

use Fleet\Domain\Model\Agent;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Tests\Fleet\Support\AgentClient;
use Tests\Fleet\Support\FleetSeed;
use Tests\Inventory\Support\Documents;
use Tests\Organization\Support\Seed;
use Tests\Support\Accounts;
use Tests\Support\AuditLog;
use Tests\Support\IdentityWebTestCase;

/**
 * The three steps of an inventory (protocol §8) played by AgentClient: manifest, gzip uploads of the missing
 * snapshots, complete; a second identical inventory uploads nothing.
 */
final class InventoryApiTest extends IdentityWebTestCase
{
    private const string FIRST = '019a6b31-5e00-7f12-a3b4-c5d6e7f80912';
    private const string SECOND = '019a6b31-5e00-7f12-a3b4-c5d6e7f80913';

    #[Test]
    public function it_should_take_an_inventory_in_three_steps_and_dedup_the_next_one(): void
    {
        [$agent] = $this->approvedAgent();
        $facts = Documents::hostFacts();
        $sbom = Documents::sbom('compose:shop/php', ['pkg:composer/symfony/http-kernel@6.4.3']);
        $manifest = static fn (string $id): array => Documents::manifest($id, $facts, [Documents::subject('compose:shop/php', 'compose_service', $sbom, ['compose_project' => 'shop', 'service' => 'php'])]);

        $agent->post($this->client, '/agent/v1/inventories', $manifest(self::FIRST));
        self::assertResponseIsSuccessful();
        self::assertSame([Documents::digest($facts), Documents::digest($sbom)], $this->json()['missing']);
        self::assertSame(300, $this->json()['next_poll_in']);

        $agent->post($this->client, '/agent/v1/inventories/'.self::FIRST.'/complete', ['protocol_version' => 1]);
        $this->assertProblem(409, 'missing_snapshots');
        self::assertSame([Documents::digest($facts), Documents::digest($sbom)], $this->json()['missing']);

        $agent->putSnapshot($this->client, '/agent/v1/inventories/'.self::FIRST.'/snapshots/'.Documents::digest($sbom), $sbom, 'application/vnd.cyclonedx+json;version=1.6');
        self::assertResponseStatusCodeSame(201);
        $agent->putSnapshot($this->client, '/agent/v1/inventories/'.self::FIRST.'/snapshots/'.Documents::digest($sbom), $sbom, 'application/vnd.cyclonedx+json;version=1.6');
        self::assertResponseStatusCodeSame(200, 'Already stored.');
        $agent->putSnapshot($this->client, '/agent/v1/inventories/'.self::FIRST.'/snapshots/'.Documents::digest($facts), $facts, 'application/vnd.depverify.host-facts+json;version=1');
        self::assertResponseStatusCodeSame(201);

        $agent->post($this->client, '/agent/v1/inventories/'.self::FIRST.'/complete', ['protocol_version' => 1]);
        self::assertResponseStatusCodeSame(202);
        self::assertSame('processed', $this->fetchString('SELECT status FROM inventory_run WHERE id = ?', [self::FIRST]), 'Processed at once in the tests (the queue is synchronous there).');
        self::assertSame(2, $this->fetchInt('SELECT COUNT(*) FROM inventory_blob'));
        self::assertSame('compose:shop/php', $this->fetchString('SELECT subject_key FROM inventory_app_instance'));
        self::assertSame('pkg:composer/symfony/http-kernel@6.4.3', $this->fetchString('SELECT purl FROM inventory_package'));
        self::assertSame('6.1.0-27-amd64', $this->fetchString("SELECT facts->>'kernel' FROM inventory_host"));

        $agent->post($this->client, '/agent/v1/inventories', $manifest(self::SECOND));
        self::assertSame([], $this->json()['missing'], 'Nothing changed: nothing to upload.');
        $agent->post($this->client, '/agent/v1/inventories/'.self::SECOND.'/complete', ['protocol_version' => 1]);
        self::assertResponseStatusCodeSame(202);

        $entries = AuditLog::entries($this->connection(), 'inventory.');
        $outcomes = [];
        foreach ($entries as $entry) {
            $outcomes[$entry['action']][] = $entry['outcome'];
            self::assertNotNull($entry['team_id'], 'Scoped to the team of the agent.');
        }
        ksort($outcomes);
        self::assertSame(['inventory.complete' => ['denied', 'success', 'success'], 'inventory.process' => ['success', 'success'], 'inventory.submit_manifest' => ['success', 'success']], array_map(static function (array $o): array {
            sort($o);

            return $o;
        }, $outcomes));
        self::assertSame(1, $this->fetchInt('SELECT COUNT(*) FROM inventory_app_instance'), 'The same application, followed.');
    }

    #[Test]
    public function it_should_refuse_what_does_not_follow_the_protocol(): void
    {
        [$agent, $agentId] = $this->approvedAgent();
        $facts = Documents::hostFacts();
        $agent->post($this->client, '/agent/v1/inventories', Documents::manifest(self::FIRST, $facts, [Documents::subject('compose:blog/php', 'compose_service', '{}', ['compose_project' => 'blog', 'service' => 'php'])]));
        $this->assertProblem(403, 'scope_violation');

        $agent->post($this->client, '/agent/v1/inventories', Documents::manifest(self::FIRST, $facts, []));
        $agent->putSnapshot($this->client, '/agent/v1/inventories/'.self::FIRST.'/snapshots/'.Documents::digest($facts), $facts.' ', 'application/vnd.depverify.host-facts+json;version=1');
        $this->assertProblem(422, 'digest_mismatch');
        $agent->putSnapshot($this->client, '/agent/v1/inventories/'.self::SECOND.'/snapshots/'.Documents::digest($facts), $facts, 'application/vnd.depverify.host-facts+json;version=1');
        $this->assertProblem(404, 'not_found');

        $this->connection()->executeStatement("UPDATE fleet_agent SET status = 'pending' WHERE id = ?", [$agentId]);
        $agent->post($this->client, '/agent/v1/inventories/'.self::FIRST.'/complete', ['protocol_version' => 1]);
        $this->assertProblem(403, 'agent_pending');
        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM inventory_blob'));
    }

    /**
     * @return array{AgentClient, string}
     */
    private function approvedAgent(): array
    {
        $alice = Accounts::create(self::getContainer(), 'alice@example.com');
        $teamId = Seed::team(self::getContainer(), 'Alpha', [[$alice, TeamRole::Admin]])->id()->toString();
        [, $plain] = FleetSeed::token(self::getContainer(), $teamId);
        $agent = AgentClient::generate();
        $agent->enroll($this->client, $plain);
        $agentId = $this->fetchString('SELECT id FROM fleet_agent');
        $manager = FleetSeed::manager(self::getContainer());
        $entity = $manager->find(Agent::class, Uuid::fromString($agentId));
        self::assertNotNull($entity);
        $entity->approve(Uuid::v7(), new \DateTimeImmutable());
        $manager->flush();

        return [$agent, $agentId];
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $json = json_decode((string) $this->client->getResponse()->getContent(), true, 32, \JSON_THROW_ON_ERROR);
        \assert(\is_array($json));

        /** @var array<string, mixed> $json */
        return $json;
    }

    private function assertProblem(int $status, string $code): void
    {
        self::assertResponseStatusCodeSame($status, (string) $this->client->getResponse()->getContent());
        self::assertSame('urn:depverify:problem:'.$code, $this->json()['type'] ?? null);
    }
}
