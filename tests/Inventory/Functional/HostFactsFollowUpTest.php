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
use Tests\Support\DataTables;
use Tests\Support\IdentityWebTestCase;

/**
 * An agent enrolled before Docker answered (its container id as hostname, no OS, no engine id): the Hosts list of
 * Fleet follows what its inventories observe, and the host is promoted to its Docker engine, so a later enrollment
 * on the same engine finds it instead of creating another one.
 */
final class HostFactsFollowUpTest extends IdentityWebTestCase
{
    private const string INVENTORY = '019a6b31-5e00-7f12-a3b4-c5d6e7f80931';
    private const string ENGINE = 'b1c7a0f2-3e4d-4c5b-9a8f-7e6d5c4b3a21';

    #[Test]
    public function it_should_show_the_observed_name_and_promote_the_host_to_its_docker_engine(): void
    {
        $alice = Accounts::create(self::getContainer(), 'alice@example.com');
        $team = Seed::team(self::getContainer(), 'Alpha', [[$alice, TeamRole::Admin]]);
        $teamId = $team->id()->toString();
        [, $plain] = FleetSeed::token(self::getContainer(), $teamId);
        $agent = AgentClient::generate();
        $agent->enroll($this->client, $plain, ['hostname' => 'd0f0663a8c03', 'os' => null, 'machine_id_hash' => null]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['hostname', 'd0f0663a8c03'], [$this->fetchString('SELECT identity_kind FROM fleet_host'), $this->fetchString('SELECT identity_value FROM fleet_host')]);
        $this->approve();

        $this->send($agent, Documents::hostFacts('YggWeb'));

        $this->logIn('alice@example.com');
        $row = DataTables::draw($this->client, '/teams/'.$teamId.'/hosts', 'hosts')['data'][0];
        self::assertStringContainsString('YggWeb', (string) $row['hostname']);
        self::assertStringNotContainsString('d0f0663a8c03', (string) $row['hostname']);
        self::assertStringContainsString('Debian GNU/Linux 12 (bookworm)', (string) $row['os']);
        self::assertSame(['docker', self::ENGINE], [$this->fetchString('SELECT identity_kind FROM fleet_host'), $this->fetchString('SELECT identity_value FROM fleet_host')]);
        $promoted = AuditLog::entries($this->connection(), 'fleet.host_identity_promoted');
        self::assertSame(['success'], array_column($promoted, 'outcome'));
        self::assertStringNotContainsString(self::ENGINE, (string) json_encode($promoted), 'Never the engine id in the audit.');

        [, $again] = FleetSeed::token(self::getContainer(), $teamId);
        AgentClient::generate()->enroll($this->client, $again, ['hostname' => 'YggWeb', 'docker_engine_id' => self::ENGINE, 'machine_id_hash' => null]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $this->fetchInt('SELECT COUNT(*) FROM fleet_host'), 'The same engine: the same host, no duplicate.');
        self::assertSame([2, 1], [$this->fetchInt('SELECT COUNT(*) FROM fleet_agent'), $this->fetchInt('SELECT COUNT(DISTINCT host_id) FROM fleet_agent')], 'Both agents on it.');
    }

    private function approve(): void
    {
        $manager = FleetSeed::manager(self::getContainer());
        $entity = $manager->find(Agent::class, Uuid::fromString($this->fetchString('SELECT id FROM fleet_agent')));
        self::assertNotNull($entity);
        $entity->approve(Uuid::v7(), new \DateTimeImmutable());
        $manager->flush();
    }

    /**
     * One inventory with the host facts only (no subject): processed at once in the test environment.
     */
    private function send(AgentClient $agent, string $facts): void
    {
        $agent->post($this->client, '/agent/v1/inventories', Documents::manifest(self::INVENTORY, $facts, []));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $agent->putSnapshot($this->client, '/agent/v1/inventories/'.self::INVENTORY.'/snapshots/'.Documents::digest($facts), $facts, 'application/vnd.depverify.host-facts+json;version=1');
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $agent->post($this->client, '/agent/v1/inventories/'.self::INVENTORY.'/complete', ['protocol_version' => 1]);
        self::assertResponseStatusCodeSame(202, (string) $this->client->getResponse()->getContent());
    }
}
