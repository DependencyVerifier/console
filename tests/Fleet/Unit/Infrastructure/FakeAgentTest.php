<?php

declare(strict_types=1);

namespace Tests\Fleet\Unit\Infrastructure;

use Fleet\Application\Protocol\OrderSigner;
use Fleet\Application\Protocol\SignedRequest;
use Fleet\Domain\Model\Agent;
use Fleet\Domain\Model\AgentOrder;
use Fleet\Domain\Model\AgentOrderId;
use Fleet\Domain\Model\AgentPublicKey;
use Fleet\Infrastructure\Dev\FakeAgent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Fleet\Support\Fleet;
use Tests\Fleet\Support\FleetWorld;

use function Symfony\Component\String\u;

/**
 * The dev fake agent speaks protocol v1 as the real one must: its requests pass the console's verifier, it accepts
 * only orders signed by the pinned key, for itself, once.
 */
final class FakeAgentTest extends TestCase
{
    private const string URL = 'https://console.example.com';

    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/fake-agent-'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    #[Test]
    public function it_should_enroll_poll_and_answer_the_orders_like_the_agent(): void
    {
        $world = new FleetWorld();
        $world->clock = new MockClock(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $signer = new OrderSigner($world->signer);
        $agent = FakeAgent::create($this->file, $world->signer->publicKey()->text(), 'web-09', 'container');
        /** @var list<SignedRequest> $sent */
        $sent = [];
        /** @var \SplQueue<MockResponse> $answers */
        $answers = new \SplQueue();
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent, $answers): MockResponse {
            $headers = [];
            $lines = $options['headers'] ?? [];
            self::assertIsArray($lines);
            foreach ($lines as $line) {
                self::assertIsString($line);
                [$name, $value] = u($line)->split(': ', 2);
                $headers[$name->toString()] = $value->toString();
            }
            self::assertIsString($options['body'] ?? null);
            $sent[] = new SignedRequest($method, $url, $headers, $options['body']);

            // The console's side of an inventory (§8): every snapshot missing, each upload stored, then accepted.
            if (u($url)->endsWith('/agent/v1/inventories')) {
                $manifest = json_decode($options['body'], true);
                self::assertIsArray($manifest);
                self::assertIsArray($manifest['subjects'] ?? null);
                self::assertIsArray($manifest['host_facts'] ?? null);
                $missing = [$manifest['host_facts']['digest'] ?? null];
                foreach ($manifest['subjects'] as $subject) {
                    self::assertIsArray($subject);
                    self::assertIsArray($subject['sbom'] ?? null);
                    $missing[] = $subject['sbom']['digest'] ?? null;
                }

                return new MockResponse((string) json_encode(['inventory_id' => $manifest['inventory_id'] ?? null, 'missing' => $missing, 'next_poll_in' => 300]));
            }

            if (u($url)->containsAny('/snapshots/')) {
                return new MockResponse('', ['http_code' => 201]);
            }

            if (u($url)->endsWith('/complete')) {
                return new MockResponse('{"server_time":"2026-11-11T08:00:00Z"}', ['http_code' => 202]);
            }

            return $answers->isEmpty() ? new MockResponse('{}', ['http_code' => 500]) : $answers->dequeue();
        });

        $answers->enqueue(new MockResponse((string) json_encode(['agent_id' => Fleet::AGENT, 'status' => 'pending', 'console_key_id' => $world->signer->publicKey()->fingerprint()]), ['http_code' => 201]));
        $agent->enroll($http, self::URL, 'dvet_x');
        $enrollment = json_decode($sent[0]->body, true);
        self::assertIsArray($enrollment);
        self::assertIsArray($enrollment['host'] ?? null);
        self::assertSame(['dvet_x', 'container', 'compose', 'web-09'], [$enrollment['enrollment_token'] ?? null, $enrollment['mode'] ?? null, $enrollment['scope'] ?? null, $enrollment['host']['hostname'] ?? null]);
        self::assertIsString($enrollment['public_key'] ?? null);
        $key = AgentPublicKey::fromText($enrollment['public_key']);
        self::assertSame($key->fingerprint(), $agent->fingerprint());
        self::assertSame($key->fingerprint(), $world->verifier()->verify($sent[0], static fn (string $k): AgentPublicKey => $key)->keyId, 'Accepted by the console.');

        $good = $this->order($signer, Fleet::approvedWithId(Fleet::AGENT), Fleet::ORDER);
        $tampered = $this->order($signer, Fleet::approvedWithId(Fleet::AGENT), '019a6b2c-9f00-7a11-8b22-3c44d55e6f02');
        $tampered['signature']['value'] = $good['signature']['value'];
        $foreign = $this->order($signer, Fleet::approvedWithId('019a6b2c-8f00-7a11-8b22-3c44d55e6f02'), '019a6b2c-9f00-7a11-8b22-3c44d55e6f03');
        $answers->enqueue(new MockResponse((string) json_encode(['status' => 'approved', 'orders' => [$good, $tampered, $foreign], 'next_poll_in' => 300])));
        $poll = FakeAgent::load($this->file)->poll($http, self::URL);
        self::assertSame([['executed', null], ['rejected', 'bad_signature'], ['rejected', 'wrong_agent']], array_map(static fn (array $r): array => [$r['status'], $r['reason'] ?? null], $poll['handled']));
        self::assertSame($key->fingerprint(), $world->verifier()->verify($sent[1], static fn (string $k): AgentPublicKey => $key)->keyId);

        // The executed SCAN_NOW sends an inventory right after the poll: manifest, three gzip uploads, complete.
        self::assertSame(['POST', 'PUT', 'PUT', 'PUT', 'POST'], array_map(static fn (SignedRequest $r): string => $r->method, \array_slice($sent, 2, 5)));
        self::assertNotNull($poll['inventory']);
        self::assertSame(3, $poll['inventory']['missing']);
        $manifest = json_decode($sent[2]->body, true);
        self::assertIsArray($manifest);
        self::assertSame(['scan_now', $poll['inventory']['inventory_id']], [$manifest['trigger'] ?? null, $manifest['inventory_id'] ?? null]);
        self::assertIsArray($manifest['subjects'] ?? null);
        self::assertSame(['compose:demo/web', 'image:sha256:'.hash('sha256', 'depverify-fake-agent/demo-web')], array_column($manifest['subjects'], 'subject_key'), 'Within its Compose project only.');
        foreach (\array_slice($sent, 2, 5) as $request) {
            self::assertSame($key->fingerprint(), $world->verifier()->verify($request, static fn (string $k): AgentPublicKey => $key)->keyId, $request->targetUri);
        }

        $upload = $sent[3];
        $document = gzdecode($upload->body);
        self::assertIsString($document);
        self::assertStringEndsWith('/snapshots/sha256:'.hash('sha256', $document), $upload->targetUri, 'Named by the digest of the uncompressed document.');
        self::assertSame(['gzip', 'application/vnd.depverify.host-facts+json;version=1'], [$upload->header('Content-Encoding'), $upload->header('Content-Type')]);
        self::assertStringEndsWith('/complete', $sent[6]->targetUri);

        $answers->enqueue(new MockResponse((string) json_encode(['status' => 'approved', 'orders' => [$good], 'next_poll_in' => 300])));
        $poll = FakeAgent::load($this->file)->poll($http, self::URL);
        self::assertNull($poll['inventory'], 'A replayed SCAN_NOW runs nothing.');
        $body = json_decode($sent[7]->body, true);
        self::assertIsArray($body);
        self::assertIsArray($body['order_results'] ?? null);
        self::assertCount(3, $body['order_results'], 'The results of the previous poll.');
        self::assertSame('replayed', $poll['handled'][0]['reason'] ?? null);

        $answers->enqueue(new MockResponse('{"type":"urn:depverify:problem:agent_revoked","status":401}', ['http_code' => 401]));
        $this->expectExceptionMessageIsOrContains('HTTP 401');
        FakeAgent::load($this->file)->poll($http, self::URL);
    }

    #[Test]
    public function it_should_refuse_a_console_that_does_not_hold_the_pinned_key(): void
    {
        $world = new FleetWorld();
        $agent = FakeAgent::create($this->file, $world->signer->publicKey()->text(), 'web-09', 'host');
        $http = new MockHttpClient(new MockResponse((string) json_encode(['agent_id' => Fleet::AGENT, 'console_key_id' => 'SHA256:other']), ['http_code' => 201]));

        $this->expectExceptionMessageIsOrContains('console_key_mismatch');
        $agent->enroll($http, self::URL, 'dvet_x');
    }

    /**
     * @return array{order: array<string, mixed>, signature: array{alg: string, key_id: string, value: string}}
     */
    private function order(OrderSigner $signer, Agent $agent, string $id): array
    {
        $order = AgentOrder::scanNow(AgentOrderId::fromString($id), $agent, null, Fleet::now());
        $order->issue(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $order->deliver((string) $signer->sign($order), $signer->currentKey());

        return $signer->envelope($order);
    }
}
