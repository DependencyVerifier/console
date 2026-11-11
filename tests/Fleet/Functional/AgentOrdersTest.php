<?php

declare(strict_types=1);

namespace Tests\Fleet\Functional;

use Fleet\Domain\Model\Agent;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;
use Tests\Fleet\Support\AgentClient;
use Tests\Fleet\Support\FleetSeed;
use Tests\Organization\Support\Seed;
use Tests\Support\Accounts;
use Tests\Support\AuditLog;
use Tests\Support\DataTables;
use Tests\Support\IdentityWebTestCase;

use function Symfony\Component\String\u;

/**
 * Orders from the agent page: "Scan now" and "Configure" become signed orders delivered at the next poll; the agent
 * reports the configuration it applied; the maintenance command expires what was never answered.
 */
final class AgentOrdersTest extends IdentityWebTestCase
{
    private const string STREAM = 'text/vnd.turbo-stream.html';

    #[Test]
    public function it_should_send_a_scan_and_a_configuration_signed_at_the_next_poll(): void
    {
        [$teamId, $agentId, $agent] = $this->approvedAgent();
        $page = '/teams/'.$teamId.'/agents/'.$agentId;
        $this->logIn('alice@example.com');

        $crawler = $this->client->request('GET', $page);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($agent->key->fingerprint(), $crawler->filter('main')->text());
        self::assertSelectorTextSame('main h1', 'web-01', 'The header is the agent, not its team.');
        self::assertSelectorTextContains('main h1 + p', 'Container · Compose project shop');
        self::assertSelectorTextContains('[data-slot="breadcrumb-page"]', 'web-01');
        self::assertSelectorExists('[data-slot="breadcrumb"] a[href="/teams/'.$teamId.'/agents"]');
        $this->client->submit($crawler->filter('form[action="'.$page.'/scan"]')->form(), [], ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseIsSuccessful();
        $this->client->submit($this->client->request('GET', $page)->filter('form[action="'.$page.'/scan"]')->form(), [], ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseStatusCodeSame(422, 'One scan every five minutes.');

        $dialog = $this->client->request('GET', $page.'/configure', server: ['HTTP_TURBO_FRAME' => 'configure-agent-form']);
        self::assertSame('360', $dialog->filter('input[name="agent_config[scan_interval_minutes]"]')->attr('value'));
        $this->client->submit($dialog->filter('form')->form(), ['agent_config[scan_interval_minutes]' => 30], ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseStatusCodeSame(422, 'Below the minimum scan interval of the team (60).');
        $this->client->submit($dialog->filter('form')->form(), [
            'agent_config[scan_interval_minutes]' => 120,
            'agent_config[discovery_roots]' => "/srv\n\n  /var/www  \n",
            'agent_config[exclusions]' => '/srv/**/cache',
        ], ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('refresh', (string) $this->client->getResponse()->getContent());

        $orders = DataTables::draw($this->client, $page, 'orders')['data'];
        self::assertCount(2, $orders);
        self::assertStringContainsString('SET_CONFIG', (string) $orders[0]['type'], 'Newest first.');
        self::assertStringContainsString('Queued', (string) $orders[0]['status']);

        $agent->poll($this->client);
        $received = $this->json()['orders'] ?? [];
        self::assertIsArray($received);
        self::assertCount(2, $received);
        $order = static function (mixed $envelope): array {
            self::assertIsArray($envelope);
            self::assertIsArray($envelope['order'] ?? null);

            return $envelope['order'];
        };
        self::assertSame(['SCAN_NOW', 'SET_CONFIG'], array_map(static fn (mixed $envelope): mixed => $order($envelope)['type'] ?? null, $received));
        $config = $order($received[1]);
        self::assertSame(['config_version' => 1, 'scan_interval_minutes' => 120, 'discovery_roots' => ['/srv', '/var/www'], 'declared_paths' => [], 'exclusions' => ['/srv/**/cache']], $config['payload'] ?? null);

        $agent->poll($this->client, [['order_id' => $config['order_id'] ?? null, 'status' => 'executed', 'finished_at' => gmdate('Y-m-d\TH:i:s\Z')]], configVersion: 1);
        self::assertCount(1, (array) $this->json()['orders'], 'The scan is still waiting for its result.');
        $crawler = $this->client->request('GET', $page);
        self::assertStringContainsString('Every 120 minutes', $crawler->filter('main')->text());
        self::assertStringContainsString('1 applied · 1 asked', $crawler->filter('main')->text());
        self::assertSame(['denied', 'success'], array_column(AuditLog::entries($this->connection(), 'fleet.configure_agent'), 'outcome'));
        self::assertSame(['success', 'denied'], array_column(AuditLog::entries($this->connection(), 'fleet.request_scan'), 'outcome'));
    }

    #[Test]
    public function it_should_expire_unanswered_orders_and_forget_old_nonces(): void
    {
        [, $agentId, $agent] = $this->approvedAgent();
        $this->connection()->executeStatement("INSERT INTO fleet_agent_order (id, agent_id, team_id, type, payload, created_at, status, issued_at, expires_at, signature) SELECT ?, id, team_id, 'SCAN_NOW', '[]', NOW() - INTERVAL '1 hour', 'delivered', NOW() - INTERVAL '1 hour', NOW() - INTERVAL '45 minutes', 's' FROM fleet_agent", [Uuid::v7()->toRfc4122()]);
        $this->connection()->executeStatement("INSERT INTO fleet_agent_nonce (key_id, nonce, seen_at) VALUES ('k', 'old', NOW() - INTERVAL '11 minutes'), ('k', 'new', NOW())");
        $this->connection()->executeStatement("UPDATE fleet_agent SET last_seen_at = NOW() - INTERVAL '8 days' WHERE id = ?", [$agentId]);

        $tester = new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('app:fleet:maintain'));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('1 agents inactive, 0 revoked after 30 days inactive, 1 orders expired, 1 nonces purged.', u($tester->getDisplay())->collapseWhitespace()->toString());
        self::assertSame(['inactive', 'expired'], [$this->fetchString('SELECT status FROM fleet_agent'), $this->fetchString('SELECT status FROM fleet_agent_order')]);
        self::assertSame(['new'], $this->connection()->fetchFirstColumn("SELECT nonce FROM fleet_agent_nonce WHERE key_id = 'k'"));

        $agent->poll($this->client);
        self::assertResponseIsSuccessful();
        self::assertSame('approved', $this->fetchString('SELECT status FROM fleet_agent'), 'Back on its next request.');
    }

    /**
     * @return array{string, string, AgentClient}
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

        return [$teamId, $agentId, $agent];
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
}
