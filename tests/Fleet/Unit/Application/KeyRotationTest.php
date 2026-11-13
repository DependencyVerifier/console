<?php

declare(strict_types=1);

namespace Tests\Fleet\Unit\Application;

use Fleet\Application\Command\Agent\Handler\RecordKeyRotationHandler;
use Fleet\Application\Command\Agent\RecordAgentPoll;
use Fleet\Application\Command\Agent\RecordKeyRotation;
use Fleet\Application\Command\Order\Handler\RotateAgentKeyHandler;
use Fleet\Application\Command\Order\Handler\RotateConsoleKeyHandler;
use Fleet\Application\Command\Order\RotateAgentKey;
use Fleet\Application\Command\Order\RotateConsoleKey;
use Fleet\Application\Protocol\CanonicalJson;
use Fleet\Application\Protocol\OrderSigner;
use Fleet\Application\Protocol\ProtocolRefusal;
use Fleet\Application\Query\Admin\GetConsoleKeyRotation;
use Fleet\Application\Query\Admin\Handler\GetConsoleKeyRotationHandler;
use Fleet\Application\Query\View\ConsoleKeyRotation;
use Fleet\Domain\Model\AgentOrder;
use Fleet\Domain\Model\AgentOrderId;
use Fleet\Domain\Model\AgentPublicKey;
use Fleet\Domain\Model\ConsoleKeyRotationNotPrepared;
use Fleet\Domain\Model\InvalidOrderPayload;
use Fleet\Domain\Model\OrderStatus;
use Fleet\Domain\Model\OrderType;
use Fleet\Infrastructure\Crypto\SodiumConsoleSigner;
use Fleet\Infrastructure\Crypto\SodiumSignatureVerifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Audit\Target;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;
use Tests\Fleet\Support\Fleet;
use Tests\Fleet\Support\FleetWorld;

use function Symfony\Component\String\u;

/**
 * The rotation of a key: an agent's (ROTATE_KEY, then its proof of the new key), the console's (ROTATE_CONSOLE_KEY to
 * every approved agent, each order signed with the key its agent pins).
 */
final class KeyRotationTest extends TestCase
{
    private const string NEXT_SEED = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    #[Test]
    public function it_should_move_the_agents_to_the_next_console_key_then_sign_with_it(): void
    {
        $world = new FleetWorld();
        $world->signer = new SodiumConsoleSigner(FleetWorld::CONSOLE_SEED, self::NEXT_SEED);
        $current = $world->signer->publicKey();
        $next = $world->signer->nextKey();
        self::assertNotNull($next);
        $agent = Fleet::approved();
        $world->agents->add($agent);
        $world->agents->add(Fleet::agent(id: '019a6b2c-8f00-7a11-8b22-3c44d55e6f09'));
        $rotate = new RotateConsoleKeyHandler($world->signer, $world->agents, $world->orders, $world->clock);
        $progress = static fn (): ConsoleKeyRotation => new GetConsoleKeyRotationHandler($world->signer, $world->agents)(new GetConsoleKeyRotation());

        self::assertSame([null], [$agent->pinnedConsoleKey()], 'Not pinned before its first poll.');
        $world->poll()($this->poll());
        self::assertSame($current->fingerprint(), $agent->pinnedConsoleKey(), 'Pinned at its first poll: the current key.');

        $rotate(new RotateConsoleKey());
        $rotate(new RotateConsoleKey());
        $orders = array_values($world->orders->orders);
        self::assertCount(1, $orders, 'The approved agent only, once while on its way.');
        self::assertSame([OrderType::RotateConsoleKey, ['new_key' => $next->text()]], [$orders[0]->type(), $orders[0]->payload()]);
        self::assertEquals(new ConsoleKeyRotation($current->fingerprint(), $next->fingerprint(), 1, 0), $progress());
        self::assertFalse($progress()->isComplete());

        $world->poll()($this->poll());
        self::assertSame($current->fingerprint(), $orders[0]->signedWith(), 'Announced with the key it pins today.');
        $envelope = new OrderSigner($world->signer)->envelope($orders[0]);
        self::assertSame($current->fingerprint(), $envelope['signature']['key_id']);
        self::assertTrue(new SodiumSignatureVerifier()->verify(self::raw($envelope['signature']['value']), CanonicalJson::encode($envelope['order']), $current), 'Signed by the current key.');
        self::assertFalse(new SodiumSignatureVerifier()->verify(self::raw($envelope['signature']['value']), CanonicalJson::encode($envelope['order']), $next));

        $world->poll()($this->poll([['order_id' => $orders[0]->id()->toString(), 'status' => 'failed', 'finished_at' => '2026-11-09T08:00:00Z']]));
        self::assertSame($current->fingerprint(), $agent->pinnedConsoleKey(), 'Failed: still on the current key.');
        $rotate(new RotateConsoleKey());
        $retry = array_values($world->orders->orders)[1];
        $world->poll()($this->poll());
        $world->poll()($this->poll([['order_id' => $retry->id()->toString(), 'status' => 'executed', 'finished_at' => '2026-11-09T08:00:00Z']]));
        self::assertSame($next->fingerprint(), $agent->pinnedConsoleKey());
        self::assertTrue($progress()->isComplete());
        $rotate(new RotateConsoleKey());
        self::assertCount(2, $world->orders->orders, 'On the next key: nothing more to send.');
        $newcomer = Fleet::approvedWithId('019a6b2c-8f00-7a11-8b22-3c44d55e6f05');
        $world->agents->add($newcomer);
        $rotate(new RotateConsoleKey());
        self::assertSame([OrderType::RotateConsoleKey, $newcomer->id()->toString()], [array_values($world->orders->orders)[2]->type(), array_values($world->orders->orders)[2]->agentId()->toString()], 'An agent enrolled since: the command is run again.');

        $scan = AgentOrder::scanNow(AgentOrderId::of(Uuid::v7()), $agent, null, Fleet::now());
        $world->orders->add($scan);
        $world->poll()($this->poll());
        self::assertSame($next->fingerprint(), $scan->signedWith(), 'From now on, signed with the key it pins.');
        self::assertSame($next->fingerprint(), new OrderSigner($world->signer)->envelope($scan)['signature']['key_id']);

        $world->signer = new SodiumConsoleSigner(FleetWorld::CONSOLE_SEED);
        $later = AgentOrder::scanNow(AgentOrderId::of(Uuid::v7()), $agent, null, Fleet::now());
        $world->orders->add($later);
        $world->poll()($this->poll());
        self::assertSame(OrderStatus::Queued, $later->status(), 'A key the console no longer holds: never delivered.');
        self::assertNull($world->signer->signWith($next->fingerprint(), 'x'));
        self::assertNull($world->signer->nextKey());
        self::assertEquals(new ConsoleKeyRotation($current->fingerprint(), null, 2, 0), $progress());
        self::assertFalse($progress()->isComplete(), 'No rotation.');

        foreach ([new SodiumConsoleSigner(FleetWorld::CONSOLE_SEED), new SodiumConsoleSigner(FleetWorld::CONSOLE_SEED, FleetWorld::CONSOLE_SEED)] as $signer) {
            try {
                new RotateConsoleKeyHandler($signer, $world->agents, $world->orders, $world->clock)(new RotateConsoleKey());
                self::fail('No next key.');
            } catch (ConsoleKeyRotationNotPrepared $refused) {
                self::assertSame('console_key_rotation_not_prepared', $refused->ruleKey());
            }
        }
        self::assertSame(['fleet.rotate_console_key', []], [new RotateConsoleKey()->auditAction()->name, new RotateConsoleKey()->auditContext()->values]);
        self::assertEquals(Target::of('instance', 'console-key'), new RotateConsoleKey()->auditTarget());
    }

    #[Test]
    public function it_should_rotate_the_key_of_an_agent_once_the_new_one_proves_itself(): void
    {
        $world = new FleetWorld()->as('team_admin', Permission::HostOrder);
        $agent = Fleet::approved();
        $world->agents->add($agent);
        $ask = new RotateAgentKeyHandler($world->access(), $world->agents, $world->orders, $world->actor, $world->clock);
        $ask(new RotateAgentKey(Fleet::AGENT));
        $ask(new RotateAgentKey(Fleet::AGENT));
        $orders = array_values($world->orders->orders);
        self::assertCount(1, $orders, 'One on its way at a time.');
        self::assertSame([OrderType::RotateKey, [], Fleet::ADMIN], [$orders[0]->type(), $orders[0]->payload(), $orders[0]->requestedBy()?->toRfc4122()]);
        $order = $orders[0]->id()->toString();

        [$key, $secret] = self::pair();
        $record = new RecordKeyRotationHandler($world->agents, $world->orders, new SodiumSignatureVerifier());
        $body = static fn (string $order, string $public, string $proof): string => (string) json_encode(['protocol_version' => 1, 'order_id' => $order, 'new_public_key' => $public, 'proof' => $proof]);
        $proof = static fn (string $agentId, string $order, string $public, #[\SensitiveParameter] string $secret): string => '' === $secret ? '' : u(base64_encode(sodium_crypto_sign_detached("depverify-key-rotation-v1\n{$agentId}\n{$order}\n{$public}", $secret)))->replace('+', '-')->replace('/', '_')->trimEnd('=')->toString();
        $valid = $body($order, $key->text(), $proof(Fleet::AGENT, $order, $key->text(), $secret));
        $this->assertRefused('invalid_payload', static fn () => $record(new RecordKeyRotation(Fleet::AGENT, $valid)), 'Not delivered yet.');

        $world->poll()(new RecordAgentPoll(Fleet::AGENT, (string) json_encode(['protocol_version' => 1, 'agent_version' => '0.1.0', 'config_version' => 0, 'order_results' => []])));
        [$other, $otherSecret] = self::pair();
        foreach ([
            'invalid_payload' => $body(Uuid::v7()->toRfc4122(), $key->text(), $proof(Fleet::AGENT, $order, $key->text(), $secret)),
            'invalid_signature' => $body($order, $key->text(), $proof(Fleet::AGENT, $order, $key->text(), $otherSecret)),
        ] as $code => $request) {
            $this->assertRefused($code, static fn () => $record(new RecordKeyRotation(Fleet::AGENT, $request)));
        }
        $this->assertRefused('invalid_payload', static fn () => $record(new RecordKeyRotation(Fleet::AGENT, $body('nope', $key->text(), 'x'))));
        $this->assertRefused('invalid_payload', static fn () => $record(new RecordKeyRotation(Fleet::AGENT, $body($order, 'ed25519:short', 'x'))), 'Not a key.');
        $this->assertRefused('invalid_signature', static fn () => $record(new RecordKeyRotation(Fleet::AGENT, $body($order, $other->text(), '!!'))), 'Not base64.');
        $this->assertRefused('invalid_signature', static fn () => $record(new RecordKeyRotation(Fleet::AGENT, $body($order, $key->text(), $proof('019a6b2c-8f00-7a11-8b22-3c44d55e6f09', $order, $key->text(), $secret)))), 'A proof for another agent.');
        $this->assertRefused('unknown_agent', static fn () => $record(new RecordKeyRotation('019a6b2c-8f00-7a11-8b22-3c44d55e6f09', $valid)));
        $twin = Fleet::approvedWithId('019a6b2c-8f00-7a11-8b22-3c44d55e6f08');
        $world->agents->add($twin);
        $this->assertRefused('invalid_signature', static fn () => $record(new RecordKeyRotation(Fleet::AGENT, $body($order, $twin->publicKey()->text(), 'x'))), 'The proof before anything else.');

        $old = $agent->fingerprint();
        $record(new RecordKeyRotation(Fleet::AGENT, $valid));
        self::assertSame([$key->text(), $key->fingerprint()], [$agent->publicKey()->text(), $agent->fingerprint()]);
        self::assertNotSame($old, $agent->fingerprint());

        $second = Fleet::approvedWithId('019a6b2c-8f00-7a11-8b22-3c44d55e6f07');
        $world->agents->add($second);
        $ask(new RotateAgentKey($second->id()->toString()));
        $world->poll()(new RecordAgentPoll($second->id()->toString(), (string) json_encode(['protocol_version' => 1, 'agent_version' => '0.1.0', 'config_version' => 0, 'order_results' => []])));
        $secondOrder = array_values($world->orders->orders)[1]->id()->toString();
        $this->assertRefused('invalid_payload', static fn () => $record(new RecordKeyRotation($second->id()->toString(), $body($secondOrder, $key->text(), $proof($second->id()->toString(), $secondOrder, $key->text(), $secret)))), 'A key another live agent holds.');
        $this->assertRefused('invalid_payload', static fn () => $record(new RecordKeyRotation($second->id()->toString(), $valid)), 'The order of another agent.');

        try {
            $agent->rotateKey($agent->publicKey());
            self::fail('The same key.');
        } catch (InvalidOrderPayload) {
        }
        $pending = Fleet::agent(id: '019a6b2c-8f00-7a11-8b22-3c44d55e6f06');
        $this->assertRefusedDomain(static fn () => $pending->rotateKey($other));

        $this->assertDenied(static fn () => $ask(new RotateAgentKey('not-a-uuid')), ResourceNotFound::class);
        $world->as('team_member');
        $this->assertDenied(static fn () => $ask(new RotateAgentKey(Fleet::AGENT)), PermissionDenied::class);
        self::assertSame(['fleet.request_key_rotation', 'fleet.rotate_agent_key'], [new RotateAgentKey(Fleet::AGENT)->auditAction()->name, new RecordKeyRotation(Fleet::AGENT, '')->auditAction()->name]);
        self::assertEquals([Target::of('agent', Fleet::AGENT), Target::of('agent', Fleet::AGENT)], [new RotateAgentKey(Fleet::AGENT)->auditTarget(), new RecordKeyRotation(Fleet::AGENT, '')->auditTarget()]);
        self::assertSame([[], []], [new RotateAgentKey(Fleet::AGENT)->auditContext()->values, new RecordKeyRotation(Fleet::AGENT, '')->auditContext()->values]);
    }

    /**
     * @return array{AgentPublicKey, non-empty-string} a new key pair: the public key, the secret key
     */
    private static function pair(): array
    {
        $pair = sodium_crypto_sign_keypair();
        /** @var non-empty-string $secret */
        $secret = sodium_crypto_sign_secretkey($pair);

        return [AgentPublicKey::fromRaw(sodium_crypto_sign_publickey($pair)), $secret];
    }

    private static function raw(string $base64url): string
    {
        return (string) base64_decode(u($base64url)->replace('-', '+')->replace('_', '/')->toString(), true);
    }

    /**
     * @param list<array<string, mixed>> $results
     */
    private function poll(array $results = []): RecordAgentPoll
    {
        return new RecordAgentPoll(Fleet::AGENT, (string) json_encode(['protocol_version' => 1, 'agent_version' => '0.1.0', 'config_version' => 0, 'order_results' => $results]));
    }

    private function assertRefused(string $problem, \Closure $gesture, string $message = ''): void
    {
        try {
            $gesture();
            self::fail('Expected '.$problem.'. '.$message);
        } catch (ProtocolRefusal $refused) {
            self::assertSame($problem, $refused->problem->value, $message);
        }
    }

    private function assertRefusedDomain(\Closure $gesture): void
    {
        try {
            $gesture();
            self::fail('Expected a refusal.');
        } catch (InvalidOrderPayload $refused) {
            self::assertSame('invalid_order_payload', $refused->ruleKey());
        }
    }

    /**
     * @param class-string<\Throwable> $class
     */
    private function assertDenied(\Closure $gesture, string $class): void
    {
        try {
            $gesture();
            self::fail('Expected '.$class);
        } catch (\Throwable $refused) {
            self::assertInstanceOf($class, $refused);
        }
    }
}
