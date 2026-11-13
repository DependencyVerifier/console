<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Agent\Handler;

use Fleet\Application\Command\Agent\RecordKeyRotation;
use Fleet\Application\Port\SignatureVerifier;
use Fleet\Application\Protocol\Problem;
use Fleet\Application\Protocol\ProtocolRefusal;
use Fleet\Domain\Model\AgentId;
use Fleet\Domain\Model\AgentOrderId;
use Fleet\Domain\Model\AgentPublicKey;
use Fleet\Domain\Model\InvalidPublicKey;
use Fleet\Domain\Model\OrderStatus;
use Fleet\Domain\Model\OrderType;
use Fleet\Domain\Repository\AgentOrderRepository;
use Fleet\Domain\Repository\AgentRepository;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Protocol\AgentPayload;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\String\u;

/**
 * Checks a key rotation before the agent's key changes: a ROTATE_KEY order delivered to this agent and not answered,
 * a new key that signs the proof (`depverify-key-rotation-v1`, the agent, the order, the key) and that no live agent
 * holds. The request itself was signed by the current key (the authenticator).
 */
final readonly class RecordKeyRotationHandler implements CommandHandler
{
    public function __construct(private AgentRepository $agents, private AgentOrderRepository $orders, private SignatureVerifier $verifier)
    {
    }

    public function __invoke(RecordKeyRotation $command): void
    {
        $payload = AgentPayload::parse($command->body);
        $orderId = $payload->string('order_id');
        $agent = $this->agents->find(AgentId::fromString($command->agentId)) ?? throw new ProtocolRefusal(Problem::UnknownAgent);
        $order = Uuid::isValid($orderId) ? $this->orders->find(AgentOrderId::fromString($orderId)) : null;
        if (null === $order || !$order->agentId()->value->equals($agent->id()->value) || OrderType::RotateKey !== $order->type() || OrderStatus::Delivered !== $order->status()) {
            throw new ProtocolRefusal(Problem::InvalidPayload, 'No key rotation was asked of this agent.');
        }

        try {
            $key = AgentPublicKey::fromText($payload->string('new_public_key'));
        } catch (InvalidPublicKey) {
            throw new ProtocolRefusal(Problem::InvalidPayload, 'The new key is not an Ed25519 key.');
        }

        $proof = base64_decode(u($payload->string('proof'))->replace('-', '+')->replace('_', '/')->toString(), true);
        $message = \sprintf("depverify-key-rotation-v1\n%s\n%s\n%s", $agent->id()->toString(), $orderId, $key->text());
        if (false === $proof || !$this->verifier->verify($proof, $message, $key)) {
            throw new ProtocolRefusal(Problem::InvalidSignature, 'The new key does not sign the proof.');
        }

        if (null !== $this->agents->findLiveByFingerprint($key->fingerprint())) {
            throw new ProtocolRefusal(Problem::InvalidPayload, 'This key already belongs to an enrolled agent.');
        }

        $agent->rotateKey($key);
    }
}
