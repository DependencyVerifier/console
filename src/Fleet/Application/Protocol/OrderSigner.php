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

    /**
     * The fingerprint of the key the console signs with today.
     */
    public function currentKey(): string
    {
        return $this->signer->publicKey()->fingerprint();
    }

    /**
     * The signature of an order with the console key its agent pins (the current one by default); none when the
     * console no longer holds that key.
     */
    public function sign(AgentOrder $order, ?string $pinned = null): ?string
    {
        $signature = $this->signer->signWith($pinned ?? $this->signer->publicKey()->fingerprint(), CanonicalJson::encode(self::order($order)));

        return null === $signature ? null : u(base64_encode($signature))->replace('+', '-')->replace('/', '_')->trimEnd('=')->toString();
    }

    /**
     * @return array{order: array<string, mixed>, signature: array{alg: string, key_id: string, value: string}}
     */
    public function envelope(AgentOrder $order): array
    {
        return [
            'order' => self::order($order),
            'signature' => ['alg' => 'ed25519', 'key_id' => $order->signedWith() ?? $this->signer->publicKey()->fingerprint(), 'value' => $order->signature() ?? throw new \LogicException('The order is not signed.')],
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
