<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use function Symfony\Component\String\b;
use function Symfony\Component\String\u;

/**
 * An Ed25519 public key in the protocol's text form `ed25519:<standard base64 of the 32 raw bytes>` (protocol §2),
 * and its fingerprint `SHA256:<unpadded standard base64 of SHA-256(raw key)>`, as `ssh-keygen -l` prints it. The
 * fingerprint is the `keyid` of the agent's signatures and of the console's orders.
 */
final readonly class AgentPublicKey
{
    public const int BYTES = 32;

    private function __construct(public string $raw)
    {
    }

    public static function fromText(string $text): self
    {
        $match = u($text)->match('#^ed25519:([A-Za-z0-9+/]{43}=)$#');
        $encoded = $match[1] ?? null;
        $raw = \is_string($encoded) ? base64_decode($encoded, true) : false;
        if (false === $raw || self::BYTES !== b($raw)->length()) {
            throw new InvalidPublicKey();
        }

        return new self($raw);
    }

    public static function fromRaw(string $raw): self
    {
        if (self::BYTES !== b($raw)->length()) {
            throw new InvalidPublicKey();
        }

        return new self($raw);
    }

    public function text(): string
    {
        return 'ed25519:'.base64_encode($this->raw);
    }

    public function fingerprint(): string
    {
        return 'SHA256:'.u(base64_encode(hash('sha256', $this->raw, true)))->trimEnd('=')->toString();
    }
}
