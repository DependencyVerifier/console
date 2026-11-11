<?php

declare(strict_types=1);

namespace Fleet\Application\Port;

use Fleet\Domain\Model\AgentPublicKey;

/**
 * Ed25519 verification of an agent's signature over a signature base (protocol §4).
 */
interface SignatureVerifier
{
    public function verify(string $signature, string $message, AgentPublicKey $key): bool;
}
