<?php

declare(strict_types=1);

namespace Fleet\Application\Protocol;

use Fleet\Application\Port\ConsoleSigner;
use Fleet\Domain\Model\AgentOrder;

use function Symfony\Component\String\u;

/**
 * The signed order envelope of protocol §7.1: Ed25519 signature, by the console key, of the RFC 8785 canonical JSON
 * of `order`, base64url without padding. The validity window is fixed by AgentOrder::issue() before signing.
 */
final readonly class OrderSigner
{
    public function __construct(private ConsoleSigner $signer)
    {
    }

    public function sign(AgentOrder $order): string
    {
        $signature = base64_encode($this->signer->sign(CanonicalJson::encode(self::order($order))));

        return u($signature)->replace('+', '-')->replace('/', '_')->trimEnd('=')->toString();
    }

    /**
     * @return array{order: array<string, mixed>, signature: array{alg: string, key_id: string, value: string}}
     */
    public function envelope(AgentOrder $order): array
    {
        return [
            'order' => self::order($order),
            'signature' => ['alg' => 'ed25519', 'key_id' => $this->signer->publicKey()->fingerprint(), 'value' => $order->signature() ?? throw new \LogicException('The order is not signed.')],
        ];
    }

    public static function time(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @return array<string, mixed>
     */
    private static function order(AgentOrder $order): array
    {
        $issuedAt = $order->issuedAt() ?? throw new \LogicException('The order is not issued.');

        return [
            'order_id' => $order->id()->toString(),
            'type' => $order->type()->value,
            'agent_id' => $order->agentId()->toString(),
            'issued_at' => self::time($issuedAt),
            'not_before' => self::time($issuedAt),
            'expires_at' => self::time($order->expiresAt() ?? $issuedAt),
            'payload' => [] === $order->payload() ? new \stdClass() : $order->payload(),
        ];
    }
}
