<?php

declare(strict_types=1);

namespace Fleet\Application\Port;

use Fleet\Domain\Model\AgentPublicKey;

/**
 * The console's Ed25519 key: it signs the orders, and every agent pins its public key at install
 * (`AGENT_CONSOLE_PUBKEY`). The private part never leaves the server.
 */
interface ConsoleSigner
{
    public function publicKey(): AgentPublicKey;

    /**
     * @return string the raw 64-byte signature
     */
    public function sign(string $message): string;
}
