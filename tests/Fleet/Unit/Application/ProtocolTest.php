<?php

declare(strict_types=1);

namespace Tests\Fleet\Unit\Application;

use Fleet\Application\Protocol\OrderSigner;
use Fleet\Application\Protocol\Problem;
use Fleet\Application\Protocol\ProtocolRefusal;
use Fleet\Application\Protocol\RequestVerifier;
use Fleet\Domain\Model\AgentConfig;
use Fleet\Domain\Model\AgentOrder;
use Fleet\Domain\Model\AgentOrderId;
use Fleet\Domain\Model\OrderType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Protocol\AgentPayload;
use Shared\Application\Protocol\PayloadRefused;
use Tests\Fleet\Support\Fleet;
use Tests\Fleet\Support\FleetWorld;
use Tests\Fleet\Support\ProtocolVectors;

use function Symfony\Component\String\u;

final class ProtocolTest extends TestCase
{
    #[Test]
    public function it_should_sign_the_order_of_the_vector_byte_for_byte(): void
    {
        $vector = ProtocolVectors::order();
        $world = new FleetWorld();
        $agent = Fleet::approvedWithId('019a6b2c-1f00-7a11-8b22-3c44d55e6f01');
        $order = AgentOrder::setConfig(AgentOrderId::fromString('019a6b2e-7c40-7d3e-9f1a-2b3c4d5e6f70'), $agent, new AgentConfig(360, ['/var/www', '/srv'], ['/opt/legacy-app'], ['/var/www/**/node_modules/.cache'], 3), null, Fleet::now());
        $order->issue(new \DateTimeImmutable('2026-11-09 09:00:00', new \DateTimeZone('Europe/Paris')));
        $signer = new OrderSigner($world->signer);

        $order->deliver((string) $signer->sign($order), $signer->currentKey());
        self::assertSame(ProtocolVectors::string($vector, 'signed_order.signature.value'), $order->signature());
        self::assertSame(ProtocolVectors::map($vector, 'signed_order'), json_decode((string) json_encode($signer->envelope($order)), true), 'Times in UTC with a Z.');
    }

    #[Test]
    public function it_should_sign_an_empty_payload_as_an_empty_object(): void
    {
        $world = new FleetWorld();
        $order = AgentOrder::scanNow(AgentOrderId::fromString(Fleet::ORDER), Fleet::approved(), null, Fleet::now());
        $signer = new OrderSigner($world->signer);
        try {
            $signer->sign($order);
            self::fail('Signed before being issued.');
        } catch (\LogicException) {
            self::assertSame(OrderType::ScanNow, $order->type());
        }

        $order->issue(Fleet::now());
        try {
            $signer->envelope($order);
            self::fail('An envelope without signature.');
        } catch (\LogicException) {
            self::assertNull($order->signature());
        }

        $order->deliver((string) $signer->sign($order), $signer->currentKey());
        $envelope = $signer->envelope($order);
        self::assertEquals(new \stdClass(), $envelope['order']['payload']);
        self::assertSame('2026-11-09T08:15:00Z', $envelope['order']['expires_at']);
        self::assertSame(['alg' => 'ed25519', 'key_id' => $world->signer->publicKey()->fingerprint(), 'value' => $order->signature()], $envelope['signature']);
        self::assertSame(86, u((string) $order->signature())->length(), 'base64url without padding.');
        self::assertFalse(u((string) $order->signature())->containsAny(['+', '/', '=']));
    }

    #[Test]
    public function it_should_read_a_body_field_by_field(): void
    {
        $payload = AgentPayload::parse('{"protocol_version":1,"s":"x","n":3,"o":{"a":"b"},"e":{},"l":[{"k":"v"},{}],"z":null,"unknown":[1]}');
        self::assertSame(['x', 'x', null, null, 3], [$payload->string('s'), $payload->optionalString('s'), $payload->optionalString('z'), $payload->optionalString('missing'), $payload->int('n')]);
        self::assertSame('b', $payload->object('o')->string('a'));
        self::assertSame('b', $payload->optionalObject('o')?->string('a'));
        self::assertNull($payload->optionalObject('z'));
        self::assertNull($payload->optionalObject('missing'));
        self::assertNull($payload->object('e')->optionalString('a'), 'An empty object.');
        self::assertSame('v', $payload->objects('l')[0]->string('k'));
        self::assertCount(2, $payload->objects('l'));
        self::assertSame([], $payload->objects('missing'));
    }

    /**
     * @return iterable<string, array{string, Problem, \Closure(AgentPayload): mixed}>
     */
    public static function invalidBodies(): iterable
    {
        $ok = '{"protocol_version":1,"s":"x","n":3,"f":1.5,"o":{"a":1},"list":[1],"l":[{"k":"v"}],"bad":[1],"map":{"a":{"b":1}}}';
        $none = static fn (AgentPayload $p): null => null;
        yield 'not JSON' => ['{', Problem::InvalidPayload, $none];
        yield 'a list' => ['[1]', Problem::InvalidPayload, $none];
        yield 'a scalar' => ['1', Problem::InvalidPayload, $none];
        yield 'too deep' => [u('[')->repeat(17)->toString().u(']')->repeat(17)->toString(), Problem::InvalidPayload, $none];
        yield 'no version' => ['{"a":1}', Problem::UnsupportedProtocolVersion, $none];
        yield 'version 2' => ['{"protocol_version":2}', Problem::UnsupportedProtocolVersion, $none];
        yield 'version as a string' => ['{"protocol_version":"1"}', Problem::UnsupportedProtocolVersion, $none];
        yield 'missing string' => [$ok, Problem::InvalidPayload, static fn (AgentPayload $p): string => $p->string('missing')];
        yield 'number as string' => [$ok, Problem::InvalidPayload, static fn (AgentPayload $p): ?string => $p->optionalString('n')];
        yield 'string as int' => [$ok, Problem::InvalidPayload, static fn (AgentPayload $p): int => $p->int('s')];
        yield 'float as int' => [$ok, Problem::InvalidPayload, static fn (AgentPayload $p): int => $p->int('f')];
        yield 'missing int' => [$ok, Problem::InvalidPayload, static fn (AgentPayload $p): int => $p->int('missing')];
        yield 'list as object' => [$ok, Problem::InvalidPayload, static fn (AgentPayload $p): AgentPayload => $p->object('list')];
        yield 'string as object' => [$ok, Problem::InvalidPayload, static fn (AgentPayload $p): AgentPayload => $p->object('s')];
        yield 'missing object' => [$ok, Problem::InvalidPayload, static fn (AgentPayload $p): AgentPayload => $p->object('missing')];
        yield 'string as optional object' => [$ok, Problem::InvalidPayload, static fn (AgentPayload $p): ?AgentPayload => $p->optionalObject('s')];
        yield 'object as list' => [$ok, Problem::InvalidPayload, static fn (AgentPayload $p): array => $p->objects('o')];
        yield 'string as list' => [$ok, Problem::InvalidPayload, static fn (AgentPayload $p): array => $p->objects('s')];
        yield 'list of numbers' => [$ok, Problem::InvalidPayload, static fn (AgentPayload $p): array => $p->objects('bad')];
        yield 'list of lists' => ['{"protocol_version":1,"l":[[1]]}', Problem::InvalidPayload, static fn (AgentPayload $p): array => $p->objects('l')];
    }

    /**
     * @param \Closure(AgentPayload): mixed $read
     */
    #[Test]
    #[DataProvider('invalidBodies')]
    public function it_should_refuse_a_body_that_does_not_follow_the_protocol(string $body, Problem $problem, \Closure $read): void
    {
        try {
            $read(AgentPayload::parse($body));
            self::fail('Expected '.$problem->value);
        } catch (PayloadRefused $refusal) {
            self::assertSame($problem->value, $refusal->ruleKey());
            self::assertNotSame($refusal->ruleKey(), $refusal->getMessage(), 'A detail for the agent.');
        }
    }

    #[Test]
    public function it_should_name_each_problem_with_its_status(): void
    {
        $statuses = [];
        foreach (Problem::cases() as $problem) {
            $statuses[$problem->value] = $problem->status();
            self::assertSame('urn:depverify:problem:'.$problem->value, $problem->type());
            self::assertNotSame('', $problem->title());
        }

        self::assertSame([
            'invalid_payload' => 400, 'unsupported_protocol_version' => 400, 'invalid_signature' => 401, 'invalid_digest' => 401,
            'replayed_request' => 401, 'clock_skew' => 401, 'unknown_agent' => 401, 'agent_revoked' => 401, 'token_invalid' => 401,
            'token_expired' => 401, 'agent_pending' => 403, 'scope_violation' => 403, 'missing_snapshots' => 409, 'digest_mismatch' => 422, 'not_found' => 404, 'payload_too_large' => 413, 'quota_exceeded' => 409,
            'rate_limited' => 429,
        ], $statuses);
        self::assertCount(18, array_unique(array_map(static fn (Problem $p): string => $p->title(), Problem::cases())));
        self::assertSame(Problem::QuotaExceeded, Problem::ofRule('quota'));
        self::assertSame(Problem::TokenExpired, Problem::ofRule('token_expired'));
        self::assertSame(Problem::InvalidPayload, Problem::ofRule('agent_not_pending'));
        self::assertSame(Problem::ScopeViolation, Problem::ofRule('scope_violation'));
        self::assertSame('', new ProtocolRefusal(Problem::NotFound)->detail);
    }

    #[Test]
    public function it_should_bound_the_body_by_route(): void
    {
        self::assertSame(20971520, RequestVerifier::maxBytesOf('PUT', '/agent/v1/inventories/i/snapshots/sha256:x'));
        self::assertSame(1048576, RequestVerifier::maxBytesOf('POST', '/agent/v1/inventories/i/snapshots/sha256:x'), 'An upload is a PUT.');
        self::assertSame(1048576, RequestVerifier::maxBytesOf('PUT', '/agent/v1/inventories/i/snapshots/sha256:x/more'));
        self::assertSame(1048576, RequestVerifier::maxBytesOf('PUT', '/x/agent/v1/inventories/i/snapshots/s'));
        self::assertSame(1048576, RequestVerifier::maxBytesOf('POST', '/agent/v1/poll'));
        self::assertTrue(RequestVerifier::isBodyTooLarge('ab', 1));
        self::assertFalse(RequestVerifier::isBodyTooLarge('a', 1));
    }

    #[Test]
    public function it_should_bound_the_body_to_one_mebibyte(): void
    {
        self::assertFalse(RequestVerifier::isBodyTooLarge(u('a')->repeat(1048576)->toString()));
        self::assertTrue(RequestVerifier::isBodyTooLarge(u('a')->repeat(1048577)->toString()));
        self::assertSame('sha-256=:47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU=:', RequestVerifier::digestOf(''));
    }
}
