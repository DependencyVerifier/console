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
    /**
     * The key the console signs with (enrollment, install.sh).
     */
    public function publicKey(): AgentPublicKey;

    /**
     * The key the agents move to during a rotation (APP_AGENT_SIGNING_KEY_NEXT), none otherwise.
     */
    public function nextKey(): ?AgentPublicKey;

    /**
     * @return string the raw 64-byte signature
     */
    public function sign(string $message): string;

    /**
     * The raw signature with the key of this fingerprint (the current or the next one); none for another key.
     */
    public function signWith(string $fingerprint, string $message): ?string;
}
