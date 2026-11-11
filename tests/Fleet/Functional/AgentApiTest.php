<?php

declare(strict_types=1);

namespace Tests\Fleet\Functional;

use Fleet\Application\Port\ConsoleSigner;
use Fleet\Application\Protocol\CanonicalJson;
use Fleet\Domain\Model\Agent;
use Fleet\Domain\Model\AgentId;
use Fleet\Domain\Model\AgentOrder;
use Fleet\Domain\Model\AgentOrderId;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Tests\Fleet\Support\AgentClient;
use Tests\Fleet\Support\FleetSeed;
use Tests\Organization\Support\Seed;
use Tests\Support\Accounts;
use Tests\Support\AuditLog;
use Tests\Support\IdentityWebTestCase;

use function Symfony\Component\String\u;

/**
 * The signed agent API, played by AgentClient as the Rust agent would: enrollment (201, then 200 on retry), poll
 * while pending, approval, a SCAN_NOW delivered signed by the console key, its result, then revocation.
 */
final class AgentApiTest extends IdentityWebTestCase
{
    #[Test]
    public function it_should_enroll_poll_deliver_a_signed_order_and_stop_a_revoked_agent(): void
    {
        $teamId = $this->team();
        [, $plain] = FleetSeed::token(self::getContainer(), $teamId);
        $agent = AgentClient::generate();

        $agent->enroll($this->client, $plain);
        self::assertResponseStatusCodeSame(201);
        $enrolled = $this->json();
        self::assertSame(['pending', $agent->key->fingerprint(), $this->consoleKeyId(), 60], [$enrolled['status'], $enrolled['fingerprint'], $enrolled['console_key_id'], $enrolled['next_poll_in']]);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $this->text('server_time'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $agentId = $this->text('agent_id');
        self::assertSame($teamId, $this->fetchString('SELECT team_id FROM fleet_agent WHERE id = ?', [$agentId]));
        self::assertSame('web-01', $this->fetchString('SELECT h.hostname FROM fleet_host h JOIN fleet_agent a ON a.host_id = h.id WHERE a.id = ?', [$agentId]));
        self::assertSame(1, $this->fetchInt('SELECT uses FROM fleet_enrollment_token'));

        $agent->enroll($this->client, $plain);
        self::assertResponseStatusCodeSame(200, 'A retry with the same token and key answers the first agent.');
        self::assertSame($agentId, $this->json()['agent_id']);
        self::assertSame(1, $this->fetchInt('SELECT COUNT(*) FROM fleet_agent'));

        $agent->poll($this->client);
        self::assertResponseIsSuccessful();
        self::assertSame(['pending', [], 60], [$this->json()['status'], $this->json()['orders'], $this->json()['next_poll_in']]);

        $this->approve($agentId);
        $agent->poll($this->client);
        self::assertSame(['approved', [], 300], [$this->json()['status'], $this->json()['orders'], $this->json()['next_poll_in']]);

        $orderId = $this->scanNow($agentId);
        $agent->poll($this->client);
        $orders = $this->json()['orders'];
        self::assertIsArray($orders);
        self::assertCount(1, $orders);
        $envelope = $orders[0];
        self::assertIsArray($envelope);
        $this->assertSignedByTheConsole($envelope, $orderId, $agentId);

        $agent->poll($this->client);
        self::assertCount(1, (array) $this->json()['orders'], 'Delivered again, same signature, until its result comes.');
        self::assertSame($envelope, ((array) $this->json()['orders'])[0]);

        $agent->poll($this->client, [['order_id' => $orderId, 'status' => 'executed', 'finished_at' => gmdate('Y-m-d\TH:i:s\Z')]]);
        self::assertSame([], $this->json()['orders']);
        self::assertSame('executed', $this->fetchString('SELECT status FROM fleet_agent_order WHERE id = ?', [$orderId]));

        $this->connection()->executeStatement("UPDATE fleet_agent SET status = 'revoked' WHERE id = ?", [$agentId]);
        $agent->poll($this->client);
        $this->assertProblem(401, 'agent_revoked');

        $enrollments = AuditLog::entries($this->connection(), 'fleet.enroll_agent');
        self::assertSame(['success', 'success'], array_column($enrollments, 'outcome'));
        self::assertSame($teamId, $enrollments[0]['team_id'], 'Scoped to the team of the new agent.');
        self::assertSame([], AuditLog::entries($this->connection(), 'fleet.poll'), 'A plain poll writes no entry.');
        $refusals = AuditLog::entries($this->connection(), 'fleet.agent_request');
        self::assertSame(['rule:agent_revoked'], array_column($refusals, 'reason'));
        self::assertStringContainsString($agent->key->fingerprint(), $refusals[0]['context']);
        self::assertStringNotContainsString($plain, (string) json_encode(AuditLog::entries($this->connection(), 'fleet.')), 'The token is never audited.');
    }

    #[Test]
    public function it_should_approve_at_enrollment_with_an_approved_reusable_token_only(): void
    {
        $teamId = $this->team();
        [, $pendingToken] = FleetSeed::token(self::getContainer(), $teamId, reusable: true);
        [, $approvedToken] = FleetSeed::token(self::getContainer(), $teamId, reusable: true, approved: true);

        AgentClient::generate()->enroll($this->client, $pendingToken);
        self::assertSame('pending', $this->json()['status']);
        $second = AgentClient::generate();
        $second->enroll($this->client, $approvedToken, ['machine_id_hash' => u('a')->repeat(64)->toString()]);
        self::assertSame(['approved', 300], [$this->json()['status'], $this->json()['next_poll_in']]);
        self::assertSame(2, $this->fetchInt('SELECT COUNT(*) FROM fleet_host'), 'Another machine id: another host.');

        AgentClient::generate()->enroll($this->client, $approvedToken, ['machine_id_hash' => u('a')->repeat(64)->toString()]);
        self::assertSame(2, $this->fetchInt('SELECT COUNT(*) FROM fleet_host'), 'Same machine: same host.');
        self::assertSame(3, $this->fetchInt('SELECT COUNT(*) FROM fleet_agent'));
    }

    #[Test]
    public function it_should_refuse_used_expired_unknown_tokens_and_a_full_team(): void
    {
        $teamId = $this->team();
        [, $single] = FleetSeed::token(self::getContainer(), $teamId);
        AgentClient::generate()->enroll($this->client, $single);
        self::assertResponseStatusCodeSame(201);

        AgentClient::generate()->enroll($this->client, $single);
        $this->assertProblem(401, 'token_invalid');
        AgentClient::generate()->enroll($this->client, 'dvet_unknown');
        $this->assertProblem(401, 'token_invalid');
        [, $expired] = FleetSeed::token(self::getContainer(), $teamId, reusable: true, expiresIn: '+1 second');
        sleep(2);
        AgentClient::generate()->enroll($this->client, $expired);
        $this->assertProblem(401, 'token_expired');

        $this->connection()->executeStatement('UPDATE organization_team SET quota_max_agents = 1 WHERE id = ?', [$teamId]);
        [$token, $another] = FleetSeed::token(self::getContainer(), $teamId);
        AgentClient::generate()->enroll($this->client, $another);
        $this->assertProblem(409, 'quota_exceeded');
        self::assertSame(0, $this->fetchInt('SELECT uses FROM fleet_enrollment_token WHERE id = ?', [$token->id()->toString()]), 'Rolled back: the token is still usable.');

        AgentClient::generate()->enroll($this->client, $another, mode: 'embedded');
        $this->assertProblem(400, 'invalid_payload');
        self::assertSame(['rule:token_invalid', 'rule:token_invalid', 'rule:token_expired', 'rule:quota', 'rule:invalid_payload'], array_column(array_filter(AuditLog::entries($this->connection(), 'fleet.enroll_agent'), static fn (array $e): bool => 'denied' === $e['outcome']), 'reason'));
    }

    #[Test]
    public function it_should_refuse_every_deviation_from_the_signature_profile(): void
    {
        $teamId = $this->team();
        [, $plain] = FleetSeed::token(self::getContainer(), $teamId);
        $agent = AgentClient::generate();
        $agent->enroll($this->client, $plain);

        $nonce = AgentClient::nonce();
        $agent->poll($this->client, tamper: ['nonce' => $nonce]);
        self::assertResponseIsSuccessful();
        $agent->poll($this->client, tamper: ['nonce' => $nonce]);
        $this->assertProblem(401, 'replayed_request');

        $agent->poll($this->client, tamper: ['created' => 301]);
        $this->assertProblem(401, 'clock_skew');
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $this->text('server_time'));
        $agent->poll($this->client, tamper: ['body' => '{"protocol_version":1}']);
        $this->assertProblem(401, 'invalid_digest');
        AgentClient::generate()->poll($this->client);
        $this->assertProblem(401, 'unknown_agent');
        $agent->poll($this->client, tamper: ['keyid' => AgentClient::generate()->key->fingerprint()]);
        $this->assertProblem(401, 'unknown_agent');
        $agent->poll($this->client, tamper: ['unsigned' => true]);
        $this->assertProblem(401, 'invalid_signature');

        $this->client->request('GET', 'https://localhost/agent/v1/poll');
        $this->assertProblem(400, 'invalid_payload', 'Never a redirect to the login page.');
        $agent->post($this->client, '/agent/v1/unknown', ['protocol_version' => 1]);
        $this->assertProblem(404, 'not_found');
        $agent->post($this->client, '/agent/v1/poll', ['protocol_version' => 2]);
        $this->assertProblem(400, 'unsupported_protocol_version');

        $this->client->request('POST', 'https://localhost/agent/v1/poll', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_CONTENT_LENGTH' => '1048577'], content: '{}');
        $this->assertProblem(413, 'payload_too_large', 'The declared length is refused before the body is read.');

        self::assertSame(
            ['rule:replayed_request', 'rule:clock_skew', 'rule:invalid_digest', 'rule:unknown_agent', 'rule:unknown_agent', 'rule:invalid_signature', 'rule:payload_too_large'],
            array_column(AuditLog::entries($this->connection(), 'fleet.agent_request'), 'reason'),
        );
        self::assertSame(['rule:unsupported_protocol_version'], array_column(AuditLog::entries($this->connection(), 'fleet.poll'), 'reason'), 'Authenticated, refused by the poll: audited by the bus.');
    }

    private function team(): string
    {
        $alice = Accounts::create(self::getContainer(), 'alice@example.com');

        return Seed::team(self::getContainer(), 'Alpha', [[$alice, TeamRole::Admin]])->id()->toString();
    }

    private function approve(string $agentId): void
    {
        $manager = FleetSeed::manager(self::getContainer());
        $agent = $manager->find(Agent::class, Uuid::fromString($agentId));
        self::assertNotNull($agent);
        $agent->approve(Uuid::v7(), new \DateTimeImmutable());
        $manager->flush();
    }

    private function scanNow(string $agentId): string
    {
        $manager = FleetSeed::manager(self::getContainer());
        $agent = $manager->find(Agent::class, AgentId::fromString($agentId)->value);
        self::assertNotNull($agent);
        $order = AgentOrder::scanNow(AgentOrderId::of(Uuid::v7()), $agent, null, new \DateTimeImmutable());
        $manager->persist($order);
        $manager->flush();

        return $order->id()->toString();
    }

    private function consoleKeyId(): string
    {
        $signer = self::getContainer()->get('test.console_signer');
        \assert($signer instanceof ConsoleSigner);

        return $signer->publicKey()->fingerprint();
    }

    /**
     * @param array<mixed> $envelope
     */
    private function assertSignedByTheConsole(array $envelope, string $orderId, string $agentId): void
    {
        $order = $envelope['order'];
        $signature = $envelope['signature'];
        self::assertIsArray($order);
        self::assertIsArray($signature);
        self::assertSame([$orderId, 'SCAN_NOW', $agentId], [$order['order_id'], $order['type'], $order['agent_id']]);
        self::assertSame($order['issued_at'], $order['not_before']);
        self::assertIsString($order['expires_at']);
        self::assertIsString($order['issued_at']);
        self::assertSame(900, strtotime($order['expires_at']) - strtotime($order['issued_at']));
        self::assertSame(['alg' => 'ed25519', 'key_id' => $this->consoleKeyId()], ['alg' => $signature['alg'], 'key_id' => $signature['key_id']]);
        $signer = self::getContainer()->get('test.console_signer');
        \assert($signer instanceof ConsoleSigner);
        self::assertIsString($signature['value']);
        $raw = base64_decode(u($signature['value'])->replace('-', '+')->replace('_', '/')->toString().'==', true);
        self::assertIsString($raw);
        self::assertNotSame('', $raw);
        $key = $signer->publicKey()->raw;
        self::assertNotSame('', $key);
        // As the agent does: canonical JSON of `order` as received (objects kept as objects, `{}` included).
        $received = json_decode((string) $this->client->getResponse()->getContent(), false, 32, \JSON_THROW_ON_ERROR);
        \assert($received instanceof \stdClass && \is_array($received->orders) && $received->orders[0] instanceof \stdClass);
        $canonical = CanonicalJson::encode($received->orders[0]->order);
        self::assertStringContainsString('"payload":{}', $canonical);
        self::assertTrue(sodium_crypto_sign_verify_detached($raw, $canonical, $key));
    }

    private function text(string $field): string
    {
        $value = $this->json()[$field] ?? null;
        self::assertIsString($value);

        return $value;
    }

    /**
     * @phpstan-impure
     *
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $json = json_decode((string) $this->client->getResponse()->getContent(), true, 32, \JSON_THROW_ON_ERROR);
        \assert(\is_array($json));

        /** @var array<string, mixed> $json */
        return $json;
    }

    private function assertProblem(int $status, string $code, string $message = ''): void
    {
        self::assertResponseStatusCodeSame($status, $message);
        self::assertSame('application/problem+json', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame('urn:depverify:problem:'.$code, $this->json()['type'] ?? null, (string) $this->client->getResponse()->getContent());
    }
}
